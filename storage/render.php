<?php
$u = App\Models\User::first() ?? App\Models\User::factory()->create();
Illuminate\Support\Facades\Auth::login($u);
App\Models\ChatMessage::where('user_id',$u->id)->delete();
App\Models\ChatMessage::create(['user_id'=>$u->id,'role'=>'user','body'=>'Quanto gastei no mercado em junho?']);
App\Models\ChatMessage::create(['user_id'=>$u->id,'role'=>'assistant','body'=>'Você gastou R$ 1.234,56 em Mercado em junho.','aprovado'=>true,'fontes'=>[['ferramenta'=>'consultar_gastos','filtros'=>['mes'=>'2026-06'],'registros'=>12,'resumo'=>'fonte: consultar_gastos (mes=2026-06); 12 registro(s)']]]);
$h = Illuminate\Support\Facades\Blade::render('<x-layouts.app title="t" heading="Visão Geral" active="dashboard"><p>corpo</p></x-layouts.app>');
echo "LAYOUT len=".strlen($h)."\n";
echo (str_contains($h,'data-store-url') ? "OK store-url\n" : "MISSING store-url\n");
echo (str_contains($h,'Quanto gastei no mercado') ? "OK user msg\n" : "MISSING user msg\n");
echo (str_contains($h,'número conferido') ? "OK conferido selo\n" : "MISSING conferido\n");
echo (str_contains($h,'font-value-label">R$ 1.234,56') ? "OK value MONO\n" : "value NOT mono\n");
echo (str_contains($h,'12 registro') ? "OK fonte chip\n" : "MISSING fonte chip\n");
echo (preg_match('/id="chat-empty"[^>]*hidden/', $h) ? "OK empty hidden\n" : "empty NOT hidden\n");
echo (str_contains($h,'/build/assets/chat-') ? "OK chat.js vited\n" : "MISSING chat.js\n");
