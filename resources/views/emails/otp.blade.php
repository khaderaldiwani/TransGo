
@component('mail::message')
# مرحبًا {{ $userName ?? 'مستخدم' }}

رمز التحقق الخاص بك هو:

@component('mail::panel')
**{{ $code }}**
@endcomponent

هذا الرمز صالح لمدة 10 دقائق تقريبًا.

شكراً，  
فريق TransGo
@endcomponent
