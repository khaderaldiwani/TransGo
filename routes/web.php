<?php

use App\Mail\OtpCodeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test', function () {
    try {
   //
   Mail::to("khaderaldiwani@gmail.com")->send(new OtpCodeMail("0000", "kkk"));
        return response('Mail sent successfully.');
    } catch (TransportExceptionInterface $e) {
        return response('Mail send failed: '.$e->getMessage(), 500);
    } catch (\Throwable $e) {
        return response('Unexpected error: '.$e->getMessage(), 500);
    }
});