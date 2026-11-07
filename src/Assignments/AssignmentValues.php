<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Assignments;

use InvalidArgumentException;
use MrNewport\LaravelFlow\Support\Limits;
use MrNewport\LaravelFlow\Support\Text;

final class AssignmentValues
{
    public static function user(mixed $user): ?string
    {
        $value = is_object($user) && is_callable([$user, 'getKey']) ? $user->getKey() : (is_object($user) ? ($user->id ?? null) : null);

        return self::valid($value) ? (string) $value : null;
    }

    /** @return list<string> */
    public static function users(mixed $values): array
    {
        return self::normalize($values, false, true);
    }

    /** @return list<string> */
    public static function emails(mixed $values): array
    {
        return self::normalize($values, true, true);
    }

    /**
     * Validate trusted saved identities without imposing a newly lowered admission budget.
     * New assignment writes must use users() instead.
     *
     * @return list<string>
     */
    public static function usersForStored(mixed $values): array
    {
        return self::normalize($values, false, false);
    }

    /**
     * Validate trusted saved email identities; retain shape, byte, duplicate and email checks.
     * New assignment writes must use emails() instead.
     *
     * @return list<string>
     */
    public static function emailsForStored(mixed $values): array
    {
        return self::normalize($values, true, false);
    }

    /** @return list<string> */
    private static function normalize(mixed $values, bool $emails, bool $admission): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException(Text::get('errors.assignees_must_be_a_list_of_integer_or_string'));
        }
        if ($admission && count($values) > Limits::value('max_assignments', 250)) {
            throw new InvalidArgumentException(Text::get('errors.the_assignment_list_exceeds_flow_max_assignments'));
        }
        $normalized = [];
        foreach ($values as $value) {
            if (! self::valid($value)) {
                throw new InvalidArgumentException(Text::get('errors.an_assignee_identity_must_be_nonempty_text_up_to'));
            }
            $identity = (string) $value;
            if ($emails && ! filter_var($identity, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(Text::get('errors.email_assignees_must_contain_valid_addresses'));
            }
            $normalized[] = $identity;
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException(Text::get('errors.assignee_identities_cannot_repeat'));
        }

        return $normalized;
    }

    private static function valid(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && trim((string) $value) !== ''
            && strlen((string) $value) <= 255 && ! preg_match('/[\x00-\x1f\x7f]/', (string) $value);
    }
}
