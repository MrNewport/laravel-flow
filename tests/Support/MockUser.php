<?php

namespace MrNewport\LaravelFlow\Tests\Support;

class MockUser
{
    public function __construct(public int $id){}

    public function getKey()
    {
        return $this->id;
    }

    public function routeNotificationForMail()
    {
        return "mockuser{$this->id}@example.com";
    }
}
