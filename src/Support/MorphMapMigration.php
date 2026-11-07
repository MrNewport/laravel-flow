<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;
use MrNewport\LaravelFlow\Models\FlowInstance;

/** Explicit conversion of existing subject type strings; no schema or history changes. */
final class MorphMapMigration
{
    /** Preview is a streaming scan; diagnostics do not form part of the reviewed fingerprint. */
    public function preview(): array
    {
        return $this->plan(false);
    }

    /** Apply only the exact reviewed mapping and rows, atomically on the Flow connection. */
    public function apply(string $expectedFingerprint): array
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)) {
            throw new InvalidArgumentException(Text::get('errors.use_the_full_lowercase_sha_256_fingerprint_from_the'));
        }

        return (new FlowInstance)->getConnection()->transaction(function () use ($expectedFingerprint): array {
            $maximum = Limits::value('max_morph_conversion_rows', 100000);
            if (FlowInstance::query()->count() > $maximum) {
                throw new LogicException(Text::get('errors.the_conversion_exceeds_flow_max_morph_conversion_rows_review'));
            }
            $plan = $this->plan(true);
            if (! hash_equals($plan['fingerprint'], $expectedFingerprint)) {
                throw new LogicException(Text::get('errors.the_morph_map_or_flow_subjects_changed_after_review'));
            }
            if ($plan['unresolved'] !== []) {
                throw new LogicException(Text::get('errors.some_flow_subject_types_have_no_unambiguous_registered_alias'));
            }

            $converted = 0;
            foreach ($plan['conversions'] as $conversion) {
                $changed = FlowInstance::query()->where('model_type', $conversion['from'])->toBase()->update(['model_type' => $conversion['to']]);
                if ($changed !== $conversion['count']) {
                    throw new LogicException(Text::get('errors.flow_subject_types_changed_while_converting_the_migration_was'));
                }
                $converted += $changed;
            }

            return ['converted' => $converted, 'fingerprint' => $plan['fingerprint']];
        });
    }

    private function plan(bool $lock): array
    {
        $started = microtime(true);
        $map = $this->mapping();
        $classAliases = array_flip($map);
        $counts = [];
        $total = 0;
        $maximum = Limits::value('max_morph_conversion_rows', 100000);
        $hash = hash_init('sha256');
        hash_update($hash, json_encode(['map' => $map], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $query = FlowInstance::query()->orderBy('id')->select(['id', 'model_type', 'model_id']);
        if ($lock) {
            $query->lockForUpdate();
        }

        // Bounded result sets also avoid PDO's buffered cursor growing with the full table.
        foreach ($query->lazyById(1000) as $instance) {
            $type = (string) $instance->model_type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $total++;
            if ($lock && $total > $maximum) {
                throw new LogicException(Text::get('errors.the_conversion_exceeds_flow_max_morph_conversion_rows'));
            }
            hash_update($hash, json_encode([(string) $instance->getKey(), $type, (string) $instance->model_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        }

        $conversions = [];
        $unresolved = [];
        ksort($counts, SORT_STRING);
        foreach ($counts as $type => $count) {
            if (isset($classAliases[$type])) {
                $conversions[] = ['from' => $type, 'to' => $classAliases[$type], 'count' => $count];
            } elseif (! isset($map[$type])) {
                $unresolved[] = ['type' => $type, 'count' => $count];
            }
        }

        return ['fingerprint' => hash_final($hash), 'total' => $total, 'conversions' => $conversions, 'unresolved' => $unresolved,
            'diagnostics' => ['elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
                'peak_memory_bytes' => memory_get_peak_usage(true), 'atomic_row_budget' => $maximum,
                'within_budget' => $total <= $maximum, 'locks_all_rows_on_apply' => true]];
    }

    /** @return array<string, class-string<Model>> */
    private function mapping(): array
    {
        return $this->validatedMapping(Relation::morphMap());
    }

    /** @return array<string, class-string<Model>> */
    private function validatedMapping(array $map): array
    {
        if ($map === []) {
            throw new InvalidArgumentException(Text::get('errors.register_the_intended_morph_aliases_before_previewing_a_conversion'));
        }

        $classes = [];
        foreach ($map as $alias => $class) {
            if (! is_string($alias) || strlen($alias) > 255 || ! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]*\z/D', $alias) || ! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw new InvalidArgumentException(Text::get('errors.conversion_aliases_must_be_portable_ascii_identifiers_letter_followed'));
            }
            $identity = strtolower($class);
            if (isset($classes[$identity])) {
                throw new LogicException(Text::get('errors.a_subject_model_has_multiple_morph_aliases_register_exactly'));
            }
            $classes[$identity] = $alias;
        }

        // Default database collations may compare aliases without case distinctions.
        if (count(array_unique(array_map('strtolower', array_keys($map)))) !== count($map)) {
            throw new LogicException(Text::get('errors.morph_aliases_differ_only_by_case_choose_unambiguous_aliases'));
        }
        foreach (array_keys($map) as $alias) {
            if (isset($classes[strtolower($alias)])) {
                throw new LogicException(Text::get('errors.a_morph_alias_conflicts_with_a_registered_model_class'));
            }
        }

        ksort($map, SORT_STRING);

        return $map;
    }
}
