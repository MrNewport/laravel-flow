<?php

namespace MrNewport\LaravelFlow\Assignments;

class AssignmentStrategyFactory
{
    public static function make(?string $strategy, ?array $params=[]): AssignmentStrategyInterface
    {
        return match($strategy){
            'single_user' => new SingleUserStrategy($params),
            'multi_user'  => new MultiUserStrategy($params),
            'email_list'  => new EmailListStrategy($params),
            default       => new SingleUserStrategy($params),
        };
    }
}
