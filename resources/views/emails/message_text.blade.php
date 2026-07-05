@isset($heading){{ $heading }}

@endisset
@isset($rawHtml){!! trim(strip_tags(str_replace(['<br>','<br/>','</p>','</div>'], "\n", $rawHtml))) !!}
@else
@foreach(($lines ?? []) as $line){!! trim(strip_tags(str_replace(['<br>','<br/>'], "\n", $line))) !!}

@endforeach
@endisset
@isset($ctaUrl){{ $ctaText ?? 'Open EYE' }}: {{ $ctaUrl }}

@endisset
@isset($replyNote){!! trim(strip_tags($replyNote)) !!}

@endisset
--
EYE Analytics — info@eye-analysis.online
@isset($unsubUrl)Unsubscribe: {{ $unsubUrl }}@endisset
