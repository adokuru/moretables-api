{!! $title !!}
{!! $subtitle !!}

{!! $restaurantName !!}
{!! $tableInfo !!}
Name: {!! $guestName !!}
Confirmation #: {!! $confirmationNumber !!}
@if($showRestaurantContactDetails)
@if($addressLineOne)
{!! $addressLineOne !!}
@endif
@if($addressLineTwo)
{!! $addressLineTwo !!}
@endif
@if($restaurantPhone)
{!! $restaurantPhone !!}
@endif
@endif
@if($menuUrl)
See Menu: {!! $menuUrl !!}
@endif
@if($directionsUrl)
Get Directions: {!! $directionsUrl !!}
@endif
