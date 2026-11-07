<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use LogicException;
use MrNewport\LaravelFlow\Support\MorphMapMigration;
use MrNewport\LaravelFlow\Support\Text;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateMorphMapCommand extends Command
{
    protected $signature = 'flow:morph-map {--apply : Convert reviewed FQCN subject types to registered aliases} {--expect= : Full fingerprint from a reviewed preview}';

    protected $description = 'Preview or explicitly convert historical Flow subject types to morph aliases';

    public function getDescription(): string
    {
        return Text::get('commands.morph_description');
    }

    public function handle(MorphMapMigration $migration): int
    {
        try {
            if ($this->option('apply')) {
                if ($this->option('expect') === null) {
                    throw new InvalidArgumentException(Text::get('errors.conversion_requires_expect_with_the_full_fingerprint_from_a'));
                }
                $result = $migration->apply((string) $this->option('expect'));
            } else {
                $result = $migration->preview();
            }
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }

        $this->output->getOutput()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", false, OutputInterface::OUTPUT_RAW);

        return isset($result['unresolved']) && $result['unresolved'] !== [] ? self::FAILURE : self::SUCCESS;
    }
}
