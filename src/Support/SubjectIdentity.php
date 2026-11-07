<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MrNewport\LaravelFlow\Models\FlowInstance;

final class SubjectIdentity
{
    /** @return array{0: string, 1: int|string} */
    public static function resolve(mixed $subject): array
    {
        if (! is_object($subject) || ! is_callable([$subject, 'getKey'])) {
            throw new InvalidArgumentException(Text::get('errors.a_flow_subject_must_expose_a_getkey_method'));
        }
        if ($subject instanceof Model) {
            if (! $subject->exists) {
                throw new InvalidArgumentException(Text::get('errors.an_eloquent_flow_subject_must_already_be_saved'));
            }
            self::assertConnection($subject);
        }
        $key = $subject->getKey();
        if ((! is_int($key) && ! is_string($key)) || (is_string($key) && $key === '')) {
            throw new InvalidArgumentException(Text::get('errors.a_flow_subject_must_have_a_nonempty_integer_or'));
        }
        $integerMax = (new FlowInstance)->getConnection()->getDriverName() === 'sqlite'
            ? '9223372036854775807' : '18446744073709551615';
        $valid = match (config('flow.morph_key_type', 'int')) {
            'int' => preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', (string) $key)
                && (strlen((string) $key) < strlen($integerMax)
                    || (strlen((string) $key) === strlen($integerMax) && strcmp((string) $key, $integerMax) <= 0)),
            'uuid' => is_string($key) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $key),
            'ulid' => is_string($key) && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/Di', $key),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException(Text::get('errors.the_subject_key_must_match_flow_morph_key_type'));
        }

        return [(string) ($subject instanceof Model ? $subject->getMorphClass() : get_class($subject)), $key];
    }

    public static function assertConnection(Model $model): void
    {
        if ($model->getConnection()->getName() !== (new FlowInstance)->getConnection()->getName()) {
            throw new InvalidArgumentException(Text::get('errors.flow_records_and_their_subject_must_share_one_database'));
        }
    }
}
