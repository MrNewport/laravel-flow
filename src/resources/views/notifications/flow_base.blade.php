@component('mail::message')
<p>{{ __('laravel-flow::messages.actioned', ['step' => \MrNewport\LaravelFlow\Support\NotificationText::singleLine($step->step_id), 'action' => \MrNewport\LaravelFlow\Support\NotificationText::singleLine($action)]) }}</p>

@component('mail::button', ['url' => config('app.url')])
{{ __('laravel-flow::messages.view_flow') }}
@endcomponent
@endcomponent
