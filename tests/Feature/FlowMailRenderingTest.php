<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Notifications\FlowBaseNotification;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowMailRenderingTest extends TestCase
{
    private function message(string $action = 'approve'): MailMessage
    {
        config(['app.url' => 'https://flow-host.invalid']);
        $step = (new FlowInstanceStep)->forceFill(['id' => 17, 'step_id' => 'review']);

        return (new FlowBaseNotification($step, $action))->toMail(new \stdClass);
    }

    public function test_notification_contains_a_real_nested_button_table_and_no_component_code(): void
    {
        $html = (string) $this->message()->render();
        $this->assertStringContainsString('<table class="action"', $html);
        $this->assertStringNotContainsString('<code>&lt;table', $html);
        $this->assertStringNotContainsString('<pre ', $html);
        $this->assertStringContainsString('href="https://flow-host.invalid"', $html);
        $this->assertStringContainsString('Step review was actioned with: approve.', preg_replace('/\s+/', ' ', strip_tags($html)));
        $text = (string) app(Markdown::class)->renderText($this->message()->markdown, $this->message()->data());
        $this->assertStringContainsString('View Flow', $text);
        $this->assertStringNotContainsString('<table', $text);
    }

    public function test_action_strings_are_literal_text_in_html_and_text_mail(): void
    {
        $action = '[Confirm](https://action.invalid) ![pixel](https://image.invalid/p) <script>alert(1)</script>';
        $html = (string) $this->message($action)->render();
        $this->assertStringNotContainsString('href="https://action.invalid"', $html);
        $this->assertStringNotContainsString('src="https://image.invalid/p"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('[Confirm](https://action.invalid)', html_entity_decode(strip_tags($html)));
        $this->assertStringContainsString('<script>alert(1)</script>', html_entity_decode(strip_tags($html)));
    }

    public function test_notification_copy_and_accessible_button_label_are_translatable(): void
    {
        app('translator')->addLines([
            'messages.subject' => 'Étape :step — :action',
            'messages.actioned' => 'Étape :step : :action.',
            'messages.view_flow' => 'Voir le workflow',
        ], 'fr', 'laravel-flow');
        app()->setLocale('fr');
        $mail = $this->message();
        $this->assertSame('Étape review — approve', $mail->subject);
        $this->assertStringContainsString('Voir le workflow', (string) $mail->render());
    }
}
