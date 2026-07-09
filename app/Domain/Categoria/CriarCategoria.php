<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Domain\Categoria\Concerns\SincronizaRegras;
use App\Models\AuditLog;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cria uma categoria do usuário (spec FE §7.12). Escrita determinística: persiste nome/cor/ícone
 * já validados na borda, sincroniza as regras do lookup (palavras-chave + apelidos, gravadas
 * normalizadas — {@see SincronizaRegras}) e audita. A unicidade do nome por usuário entre ativas
 * é garantida pelo índice parcial no banco; a borda valida antes para uma mensagem amigável.
 */
final class CriarCategoria
{
    use SincronizaRegras;

    public function criar(DadosCategoria $dados, CarbonImmutable $agora): Category
    {
        return DB::transaction(function () use ($dados): Category {
            $categoria = Category::create([
                'user_id' => $dados->userId,
                'nome' => $dados->nome,
                'cor' => $dados->cor,
                'icone' => $dados->icone,
                'arquivada' => false,
            ]);

            $this->sincronizarPalavras($categoria, $dados->palavrasChave);
            $this->sincronizarApelidos($categoria, $dados->apelidos);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'category',
                'entidade_id' => $categoria->id,
                'acao' => AuditLog::ACAO_CRIAR,
                'antes' => null,
                'depois' => ['nome' => $categoria->nome, 'cor' => $categoria->cor, 'icone' => $categoria->icone],
                'origem' => 'manual',
            ]);

            return $categoria;
        });
    }
}
