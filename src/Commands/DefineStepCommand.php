<?php

namespace MrNewport\LaravelFlow\Commands;

use Illuminate\Console\Command;
use MrNewport\LaravelFlow\Assignments\AssignmentStrategyFactory;
use MrNewport\LaravelFlow\Assignments\AssignmentValues;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelFlow\Support\Text;
use Symfony\Component\Console\Formatter\OutputFormatter;

class DefineStepCommand extends Command
{
    protected $signature = 'flow:define-step
                            {stepId : Unique ID for this step}
                            {name? : Optional display name}
                            {--notify= : true/false}
                            {--strategy= : assignment strategy name (single_user,multi_user,email_list)}
                            {--params= : JSON for assignment_params}
                            {--actions= : comma-separated transitions like "approve:next,reject:END"}';

    protected $description = 'Create or update a flow step, with optional transitions.';

    public function getDescription(): string
    {
        return Text::get('commands.define_description');
    }

    public function handle(): int
    {
        try {
            $stepId = $this->argument('stepId');
            $this->identifier($stepId);
            $name = $this->argument('name') ?? '';
            if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new \InvalidArgumentException(Text::get('errors.step_names_must_be_plain_text_up_to_255'));
            }
            $notify = $this->option('notify') === null ? false : filter_var($this->option('notify'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($notify === null) {
                throw new \InvalidArgumentException(Text::get('errors.notify_must_contain_a_boolean_value'));
            }
            $strategy = $this->option('strategy') ?: null;
            $params = $this->parseParams($this->option('params'));
            if ($strategy !== null) {
                AssignmentStrategyFactory::make($strategy, $params);
                if ($strategy === 'single_user' && isset($params['user_id'])) {
                    AssignmentValues::users([$params['user_id']]);
                }
                if ($strategy === 'multi_user') {
                    AssignmentValues::users($params['user_ids'] ?? []);
                }
                if ($strategy === 'email_list') {
                    AssignmentValues::emails($params['emails'] ?? []);
                }
            }
            $transitions = $this->parseTransitions($this->option('actions'));
        } catch (\InvalidArgumentException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }

        (new FlowStep)->getConnection()->transaction(function () use ($stepId, $name, $notify, $strategy, $params, $transitions) {
            $step = FlowStep::firstOrNew(['id' => $stepId]);
            $step->fill([
                'name' => $name, 'notify' => $notify,
                'assignment_strategy' => $strategy, 'assignment_params' => $params,
            ]);
            Persistence::save($step);
            foreach ($transitions as [$action, $next]) {
                $transition = FlowTransition::firstOrNew(['step_id' => $stepId, 'action' => $action, 'next_step_id' => $next]);
                Persistence::save($transition);
            }
        });

        foreach ($transitions as [$action, $next]) {
            $this->info(OutputFormatter::escape(Text::get('commands.transition', ['action' => $action, 'next' => $next])));
        }
        $this->info(OutputFormatter::escape(Text::get('commands.defined', ['step' => $stepId])));

        return self::SUCCESS;
    }

    private function parseTransitions(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $transitions = [];
        foreach (explode(',', $value) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException(Text::get('errors.actions_must_contain_action_target_pairs'));
            }
            [$action, $next] = $parts;
            $this->identifier($action);
            $this->identifier($next);
            $transitions[] = [$action, $next];
        }

        return $transitions;
    }

    private function parseParams(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $value = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(Text::get('errors.params_must_contain_valid_json'), previous: $exception);
        }
        if ($value !== null && ! is_array($value)) {
            throw new \InvalidArgumentException(Text::get('errors.params_must_contain_a_json_object_or_null'));
        }

        return $value;
    }

    private function identifier(string $value): void
    {
        if (trim($value) === '' || strlen($value) > 100 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException(Text::get('errors.step_ids_actions_and_targets_must_be_nonempty_text'));
        }
    }
}
