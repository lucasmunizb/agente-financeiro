<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Categoria\Concerns\SincronizaRegras;
use App\Domain\Categoria\DadosCategoria;
use App\Domain\Categoria\PaletaDeCategoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação e tradução na borda do cadastro de categoria (§7.12). Nome único por usuário entre
 * categorias não excluídas (espelha o índice parcial `categories_nome_unique`). Cor (#RRGGBB) e
 * ícone restritos à paleta fixa ({@see PaletaDeCategoria} — nada de hex solto/ícone órfão), ambos
 * opcionais. Palavras-chave e apelidos chegam como texto (separado por vírgula/nova linha) e viram
 * listas; o domínio os normaliza e deduplica.
 */
class CriarCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'nome' => [
                'required', 'string', 'max:60',
                Rule::unique('categories', 'nome')
                    ->where(fn ($q) => $q->where('user_id', $userId)->whereNull('deleted_at')),
            ],
            'cor' => ['nullable', Rule::in(PaletaDeCategoria::CORES)],
            'icone' => ['nullable', Rule::in(PaletaDeCategoria::ICONES)],
            'palavras_chave' => ['nullable', 'string', 'max:1000'],
            'apelidos' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe um nome.',
            'nome.unique' => 'Você já tem uma categoria com esse nome.',
            'cor.in' => 'Escolha uma cor da paleta.',
            'icone.in' => 'Escolha um ícone da lista.',
        ];
    }

    public function paraDominio(): DadosCategoria
    {
        return new DadosCategoria(
            userId: $this->user()->id,
            nome: trim((string) $this->input('nome')),
            cor: $this->filled('cor') ? (string) $this->input('cor') : null,
            icone: $this->filled('icone') ? (string) $this->input('icone') : null,
            palavrasChave: $this->listaDeTermos('palavras_chave'),
            apelidos: $this->listaDeTermos('apelidos'),
        );
    }

    /**
     * Quebra o texto de tags (separado por vírgula ou nova linha) em uma lista. A normalização
     * e a deduplicação ficam no domínio ({@see SincronizaRegras}).
     *
     * @return list<string>
     */
    private function listaDeTermos(string $campo): array
    {
        $bruto = (string) $this->input($campo, '');

        return collect(preg_split('/[,\n]+/', $bruto) ?: [])
            ->map(fn (string $t): string => trim($t))
            ->filter(fn (string $t): bool => $t !== '')
            ->values()
            ->all();
    }
}
