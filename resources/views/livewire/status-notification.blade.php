<div>
    @foreach($notifications as $notification)
        <div class="p-2 mb-4 border rounded-lg {{ $notification['textColor'] }} {{ $notification['borderColor'] }}">
            <div class="flex items-center gap-1">
                @if ($notification['icon'] !== '')
                    @svg($notification['icon'], ['class' => 'w-6 h-6'])
                @endif
                @if ($notification['categoryIcon'] !== '')
                    @svg($notification['categoryIcon'], ['class' => 'w-6 h-6'])
                @endif
                @if ($notification['text'] !== '')
                    <h3 class="font-medium">{{ $notification['text'] }}</h3>
                @endif
            </div>
        </div>
    @endforeach
</div>