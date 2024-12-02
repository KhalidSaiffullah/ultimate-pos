@extends('layouts.auth')
@section('title', __('superadmin::lang.pricing'))

@section('content')
    <div class="">
        @include('superadmin::layouts.partials.currency')
        <div class="">
            <div class="tw-mt-20">
                <div class="tw-flex tw-flex-col tw-items-center">
                    <div
                        class="lg:tw-w-16 md:tw-h-16 tw-w-12 tw-h-12 tw-flex tw-items-center tw-justify-center tw-mx-auto tw-overflow-hidden tw-bg-white tw-rounded-full tw-p-0.5 tw-mb-4">
                        <img src="{{ asset('img/logo-small.png') }}" alt="lock" class="tw-rounded-full tw-object-fill" />
                    </div>

                    <div class="tw-flex tw-flex-col tw-gap-2 tw-text-center">
                        <h2 class="tw-font-bold tw-text-3xl tw-text-white">@lang('superadmin::lang.pricing')</h2>
                        <h3 class="tw-text-sm tw-font-medium tw-text-white">
                            Choose your prefered {{ config('app.name', 'ultimatePOS') }} pricing plan
                        </h3>
                    </div>

                    <!-- Montly/annual-->
                    <div class="tw-flex tw-gap-2 mt-5 md:tw-mt-5">
                        <span class="tw-text-white">Montly</span>

                        <input type="checkbox" class="tw-dw-toggle tw-dw-toggle-secondary duration_check"
                            style="margin: 0px" />

                        <span class="tw-flex tw-flex-col tw-text-white"> Annual </span>
                    </div>
                </div>

                {{-- <div class="box-body tw-mt-6"> --}}
                    <div class="tw-flex tw-flex-col md:tw-flex-row tw-gap-5 md:tw-gap-0 tw-mt-5 md:tw-mt-7 tw-mb-10">
                        @include('superadmin::subscription.partials.packages', [
                            'action_type' => 'register',
                        ])
                    </div>
                {{-- </div> --}}
            </div>
        </div>
    </div>
@stop

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('.change_lang').click(function() {
                window.location = "{{ route('pricing') }}?lang=" + $(this).attr('value');
            });

            $('.duration_check').click(function() {
                if ($(this).is(':checked')) {
                    $('.months').fadeOut();
                    $('.years').fadeIn();
                } else {
                    $('.months').fadeIn();
                    $('.years').fadeOut();
                }
            });

            $('.years').fadeOut();
        })
    </script>
@endsection
