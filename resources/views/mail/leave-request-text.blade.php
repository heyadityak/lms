{!! $brand->name !!}

Hello {!! $name !!},

@foreach ($lines as $line)
{!! $line !!}
@endforeach

{!! $action !!}: {!! $url !!}
