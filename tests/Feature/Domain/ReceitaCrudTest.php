<?php

declare(strict_types=1);

use App\Domain\Receita\DadosReceita;
use App\Domain\Receita\ListarReceitas;
use App\Domain\Receita\RegistrarReceita;
use App\Models\AuditLog;
use App\Models\Income;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Receitas — leitura de listagem e cadastro (spec FE §7.10). Escrita determinística: descrição +
 * valor (centavos, regra 5) + tipo (fixa/variável) + data; audit; escopo por usuário. Listagem
 * por mês com filtro por tipo. A soma do mês continua em ReceitasDoMes (já testada).
 */

uses(RefreshDatabase::class);

function dadosReceita(User $user, array $over = []): DadosReceita
{
    return new DadosReceita(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Salário',
        valorCents: $over['valorCents'] ?? 500000,
        data: CarbonImmutable::parse($over['data'] ?? '2026-07-05', 'America/Sao_Paulo'),
        tipo: $over['tipo'] ?? Income::TIPO_FIXA,
    );
}

it('registra uma receita com os campos e audita', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $income = (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'Freela', 'valorCents' => 120000, 'tipo' => Income::TIPO_VARIAVEL]), $agora);

    expect($income->user_id)->toBe($user->id)
        ->and($income->descricao)->toBe('Freela')
        ->and($income->valor_cents)->toBe(120000)
        ->and($income->tipo)->toBe(Income::TIPO_VARIAVEL)
        ->and($income->data->toDateString())->toBe('2026-07-05');

    expect(AuditLog::where('entidade', 'income')->where('entidade_id', $income->id)
        ->where('acao', AuditLog::ACAO_CRIAR)->exists())->toBeTrue();
});

it('lista as receitas do mês, mais recentes primeiro, isoladas por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'Salário', 'data' => '2026-07-05']), CarbonImmutable::now('America/Sao_Paulo'));
    (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'Pix', 'data' => '2026-07-20', 'tipo' => Income::TIPO_VARIAVEL]), CarbonImmutable::now('America/Sao_Paulo'));
    (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'AgostoOutroMes', 'data' => '2026-08-01']), CarbonImmutable::now('America/Sao_Paulo'));
    (new RegistrarReceita)->registrar(dadosReceita($outro, ['descricao' => 'Alheia']), CarbonImmutable::now('America/Sao_Paulo'));

    $lista = (new ListarReceitas)->para($user->id, '2026-07', null);

    expect($lista->pluck('descricao')->all())->toBe(['Pix', 'Salário']); // data desc; sem agosto nem alheia
});

it('filtra a listagem por tipo', function () {
    $user = User::factory()->create();
    (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'Salário', 'tipo' => Income::TIPO_FIXA]), CarbonImmutable::now('America/Sao_Paulo'));
    (new RegistrarReceita)->registrar(dadosReceita($user, ['descricao' => 'Freela', 'tipo' => Income::TIPO_VARIAVEL, 'data' => '2026-07-12']), CarbonImmutable::now('America/Sao_Paulo'));

    expect((new ListarReceitas)->para($user->id, '2026-07', Income::TIPO_VARIAVEL)->pluck('descricao')->all())
        ->toBe(['Freela']);
});
