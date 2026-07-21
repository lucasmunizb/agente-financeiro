{{-- Estado CARREGANDO do dashboard (spec FE §7.5). Esqueleto sóbrio que espelha
     o layout populado (régua → cards → donut + próximas contas), para a tela não
     "pular" quando os dados chegam. Anunciado por leitor de tela. --}}
<div class="w-full space-y-gutter" role="status" aria-live="polite">
    <span class="sr-only">Carregando o painel…</span>

    {{-- Régua (skeleton) --}}
    <div class="notebook-card rounded-card p-6">
        <div class="mb-6 flex items-center justify-between">
            <div class="skeleton h-6 w-40"></div>
            <div class="flex gap-3">
                <div class="skeleton h-4 w-24"></div>
                <div class="skeleton h-4 w-16"></div>
            </div>
        </div>
        <div class="flex items-end justify-between gap-1 pt-4">
            @for ($i = 0; $i < 24; $i++)
                <div class="skeleton w-[2px] rounded-full {{ ['h-3', 'h-5', 'h-3', 'h-6', 'h-3', 'h-5'][$i % 6] }}"></div>
            @endfor
        </div>
    </div>

    {{-- Cards de resumo (skeleton) --}}
    <div class="@container"><div class="grid grid-cols-1 gap-gutter @lg:grid-cols-2 @5xl:grid-cols-4">
        @for ($i = 0; $i < 4; $i++)
            <div class="notebook-card flex h-32 flex-col justify-between rounded-card p-6">
                <div class="flex items-start justify-between">
                    <div class="skeleton h-3 w-24"></div>
                    <div class="skeleton h-3 w-10"></div>
                </div>
                <div class="skeleton ml-auto h-8 w-32"></div>
            </div>
        @endfor
    </div></div>

    {{-- Donut + próximas contas (skeleton) --}}
    <div class="@container"><div class="grid grid-cols-1 gap-gutter @3xl:grid-cols-3">
        <div class="notebook-card flex flex-col items-center rounded-card p-8 @3xl:col-span-1">
            <div class="mb-8 w-full">
                <div class="skeleton h-6 w-32"></div>
            </div>
            <div class="mb-8 flex h-48 w-48 items-center justify-center rounded-full border-[12px] border-surface-container-highest">
                <div class="skeleton h-10 w-20"></div>
            </div>
            <div class="w-full space-y-3">
                @for ($i = 0; $i < 4; $i++)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="skeleton h-3 w-3 rounded-full"></div>
                            <div class="skeleton h-3 w-24"></div>
                        </div>
                        <div class="skeleton h-3 w-16"></div>
                    </div>
                @endfor
            </div>
        </div>

        <div class="notebook-card rounded-card p-8 @3xl:col-span-2">
            <div class="mb-6 flex items-center justify-between">
                <div class="skeleton h-6 w-40"></div>
                <div class="skeleton h-8 w-24 rounded-lg"></div>
            </div>
            <div>
                @for ($i = 0; $i < 3; $i++)
                    <div class="notebook-line flex items-center justify-between py-4">
                        <div class="flex items-center gap-4">
                            <div class="skeleton h-10 w-10 rounded-full"></div>
                            <div class="space-y-2">
                                <div class="skeleton h-4 w-32"></div>
                                <div class="skeleton h-3 w-20"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="skeleton h-4 w-20"></div>
                            <div class="skeleton h-6 w-16 rounded-full"></div>
                        </div>
                    </div>
                @endfor
            </div>
        </div>
    </div></div>
</div>
