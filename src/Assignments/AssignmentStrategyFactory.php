<?php

namespace MrNewport\LaravelFlow\Assignments;

class AssignmentStrategyFactory
{
    public static function make(?string $strategy, ?array $params=[]): AssignmentStrategyInterface
    {
        $params ??= [];

        return match($strategy){
            'single_user' => new SingleUserStrategy($params),
            'multi_user'  => new MultiUserStrategy($params),
            'email_list'  => new EmailListStrategy($params),
            null          => new SingleUserStrategy($params),
            default       => throw new \InvalidArgumentException('Unsupported flow assignment strategy: '.$strategy),
        };
    }
}
