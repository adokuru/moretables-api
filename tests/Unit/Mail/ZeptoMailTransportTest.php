<?php

use App\Mail\ZeptoMailTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

it('sends the complete email contract through the ZeptoMail API', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.zeptomail.test/v1.1/email' => Http::response([
            'message' => 'OK',
            'request_id' => 'zeptomail-request-id',
        ], 201),
    ]);

    $email = (new Email)
        ->from(new Address('noreply@moretables.com', 'MoreTables'))
        ->to(new Address('guest@example.com', 'Guest'))
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->replyTo('support@moretables.com')
        ->subject('Reservation confirmed')
        ->html('<p>Confirmed <img src="cid:logo@moretables.com"></p>')
        ->text('Confirmed')
        ->attach('invoice-data', 'invoice.txt', 'text/plain')
        ->addPart(
            (new DataPart('logo-data', 'logo.png', 'image/png'))
                ->asInline()
                ->setContentId('logo@moretables.com'),
        );
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://moretables.com/unsubscribe>');

    config(['mail.mailers.zeptomail' => [
        'transport' => 'zeptomail',
        'endpoint' => 'https://api.zeptomail.test/v1.1/email',
        'token' => 'send-api-key',
        'timeout' => 10,
    ]]);
    Mail::purge('zeptomail');

    $transport = Mail::mailer('zeptomail')->getSymfonyTransport();
    $sentMessage = $transport->send($email);

    expect($transport)->toBeInstanceOf(ZeptoMailTransport::class)
        ->and($sentMessage?->getMessageId())->toBe('zeptomail-request-id');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.zeptomail.test/v1.1/email'
            && $request->hasHeader('Authorization', 'Zoho-enczapikey send-api-key')
            && $request['from'] === ['address' => 'noreply@moretables.com', 'name' => 'MoreTables']
            && $request['to'][0]['email_address']['address'] === 'guest@example.com'
            && $request['cc'][0]['email_address']['address'] === 'cc@example.com'
            && $request['bcc'][0]['email_address']['address'] === 'bcc@example.com'
            && $request['reply_to'][0]['address'] === 'support@moretables.com'
            && $request['subject'] === 'Reservation confirmed'
            && $request['htmlbody'] === '<p>Confirmed <img src="cid:logo@moretables.com"></p>'
            && $request['textbody'] === 'Confirmed'
            && $request['mime_headers']['List-Unsubscribe'] === '<https://moretables.com/unsubscribe>'
            && $request['attachments'][0] === [
                'content' => base64_encode('invoice-data'),
                'mime_type' => 'text/plain',
                'name' => 'invoice.txt',
            ]
            && $request['inline_images'][0] === [
                'content' => base64_encode('logo-data'),
                'mime_type' => 'image/png',
                'cid' => 'logo@moretables.com',
            ];
    });
});

it('fails the mail send when ZeptoMail rejects the request', function (): void {
    Http::fake([
        'https://api.zeptomail.test/v1.1/email' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $email = (new Email)
        ->from('noreply@moretables.com')
        ->to('guest@example.com')
        ->subject('Test')
        ->text('Test');

    expect(fn () => (new ZeptoMailTransport(
        url: 'https://api.zeptomail.test/v1.1/email',
        token: 'invalid-key',
    ))->send($email))->toThrow(TransportException::class, 'The ZeptoMail API returned HTTP 401.');
});
