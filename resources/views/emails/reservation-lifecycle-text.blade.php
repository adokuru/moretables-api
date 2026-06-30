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
@if(! $showNewReservationButton)
@if(filled($menuUrl))
See Menu: {!! $menuUrl !!}
@endif
@if(filled($directionsUrl))
Get Directions: {!! $directionsUrl !!}
@endif
@endif
@if($showReservationActions)

Add to calendar: {!! $calendarUrl !!}
Modify: {!! $modifyUrl !!}
Cancel: {!! $cancelUrl !!}
@endif
@if($showNewReservationButton)

Make a new reservation: {!! $newReservationUrl !!}
@endif
@if($showReviewButton)

Leave a review: {!! $reviewUrl !!}
@endif
