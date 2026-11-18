<!DOCTYPE html>
<html lang="es">
    <head>
        @include('partials.head', ['title' => 'Iniciar sesión'])
    </head>
    <body class="min-h-svh bg-[#1b1b1b] font-sans text-[#fffae8] antialiased selection:bg-[#ffd600] selection:text-[#1b1b1b]">
        <main class="flex min-h-svh flex-col items-center justify-center gap-8 px-5 py-12 sm:px-8">
            <section aria-labelledby="login-heading" class="relative isolate grid w-full max-w-4xl overflow-hidden border border-[#ffd600]/80 bg-[#1b1b1b] shadow-[0_0_18px_#ffd60026,0_0_70px_#ffd6000d] md:min-h-[460px] md:grid-cols-[1.08fr_1fr]">
                <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 hidden bg-linear-to-br from-[#ffd600] to-[#ffd600]/80 md:block md:[clip-path:polygon(34%_0,100%_0,100%_100%,84%_100%)]"></div>

                <div class="flex flex-col justify-center gap-10 px-7 py-10 sm:px-10 md:gap-12 md:px-12 md:py-14">
                    <div class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-[#ffd600] uppercase">
                        <span aria-hidden="true" class="size-2 bg-[#ffd600]"></span>
                        Santa Cecilia
                    </div>

                    <div class="flex flex-col gap-7">
                        <div class="flex flex-col gap-3">
                            <h1 id="login-heading" class="text-3xl font-semibold tracking-tight sm:text-4xl">Inicia sesión</h1>
                            <p class="max-w-72 text-sm leading-relaxed text-[#fffae8]/70">
                                Ingresa con tu cuenta institucional para continuar.
                            </p>
                        </div>

                        @if ($accessDenied)
                            <div role="alert" class="flex max-w-80 items-start gap-3 border border-[#ffd600]/50 bg-[#ffd600]/10 p-4 text-sm leading-relaxed text-[#fffae8]">
                                <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-[#ffd600]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.1 18.1A1.9 1.9 0 0 0 3.8 21h16.4a1.9 1.9 0 0 0 1.7-2.9L13.7 3.9a1.9 1.9 0 0 0-3.4 0Z" />
                                </svg>
                                <div class="flex flex-col gap-1">
                                    <p class="font-semibold text-[#ffd600]">Acceso no autorizado</p>
                                    <p>Tu cuenta no tiene acceso al sistema. Usa tu cuenta institucional o comunícate con el administrador.</p>
                                </div>
                            </div>
                        @endif

                        <a href="{{ route('login') }}" class="group flex min-h-12 w-full max-w-80 items-center justify-center gap-3 rounded-full border border-[#ffd600] bg-[#ffd600] px-6 py-3 text-sm font-semibold text-[#1b1b1b] shadow-[0_0_20px_#ffd6001f] transition hover:bg-[#fffae8] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#ffd600]">
                            Iniciar sesión
                            <svg aria-hidden="true" class="size-4 transition-transform motion-safe:group-hover:translate-x-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m-6-6 6 6-6 6" />
                            </svg>
                        </a>
                    </div>

                    <p class="text-xs leading-relaxed text-[#fffae8]/50">Acceso exclusivo para personal autorizado.</p>
                </div>

                <aside aria-labelledby="billing-heading" class="flex min-w-0 flex-col items-start justify-center bg-[#ffd600] px-7 py-7 text-[#1b1b1b] sm:px-10 md:items-end md:bg-transparent md:py-14 md:pr-6 md:pl-8">
                    <div class="grid w-full grid-cols-[5rem_minmax(0,1fr)] items-center gap-x-5 gap-y-3 md:flex md:max-w-56 md:-translate-y-5 md:flex-col md:gap-5 md:text-center">
                        <img
                            src="{{ asset('images/escudo_amarillo.png') }}"
                            alt="Escudo de Santa Cecilia"
                            width="1500"
                            height="1500"
                            class="row-span-2 size-20 rounded-full object-contain md:size-40"
                        >

                        <div class="flex flex-col gap-2.5">
                            <p class="text-[10px] font-semibold tracking-[0.24em] text-[#1b1b1b]/65 uppercase">Sistema de</p>
                            <h2 id="billing-heading" class="text-[1.65rem] leading-none font-semibold tracking-tight sm:text-[2rem]">Facturación</h2>
                        </div>

                        <span class="inline-flex w-fit items-center gap-2 rounded-full border border-[#1b1b1b]/20 px-3 py-1.5 text-[11px] leading-none font-medium tracking-wide">
                            <span aria-hidden="true" class="size-1.5 rounded-full bg-[#1b1b1b]"></span>
                            Versión 2.0
                        </span>
                    </div>
                </aside>
            </section>

            <p class="text-center text-xs tracking-wide text-[#fffae8]/40">Santa Cecilia</p>
        </main>
    </body>
</html>
