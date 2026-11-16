@if ($notification->isTest)
ZDARZENIE TESTOWE — nie dotyczy rzeczywistej płatności.

@endif
{!! $notification->subject !!}

@foreach ($notification->fields as $field)
{!! $field['label'] !!}: {!! $field['value'] !!}
@endforeach
