{!! $title !!}
{!! $subtitle !!}

{!! $restaurantName !!}
{!! $tableInfo !!}
Name: {!! $guestName !!}
Confirmation #: {!! $confirmationNumber !!}
@if($showRestaurantContactDetails)
@if(filled($addressLineOne))
{!! $addressLineOne !!}
@endif
@if(filled($addressLineTwo))
{!! $addressLineTwo !!}
@endif
@if(filled($restaurantPhone))
{!! $restaurantPhone !!}
@endif
@endif
@if(filled($menuUrl))
See Menu: {!! $menuUrl !!}
@endif
@if(filled($directionsUrl))
Get Directions: {!! $directionsUrl !!}
@endif
