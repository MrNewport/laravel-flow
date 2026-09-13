@component('mail::message')

    Step **{{ $step->step_id }}** was actioned with: **{{ $action }}**.

    @component('mail::button', ['url'=>config('app.url')])
        View Flow
    @endcomponent

@endcomponent
