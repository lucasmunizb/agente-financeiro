@php
    $controlador = config('legal.controlador');
    $encarregado = config('legal.encarregado');
    $contato = config('legal.contato');
    $atualizadoEm = config('legal.privacidade_atualizado_em');
@endphp

<x-layouts.guest title="Política de Privacidade | Agente Financeiro"
    description="Como o Agente Financeiro trata seus dados, com transparência e conforme a LGPD.">

    <x-legal.document current="privacy" title="Política de Privacidade"
        subtitle="Como tratamos seus dados no Agente Financeiro — em linguagem clara e conforme a Lei Geral de Proteção de Dados (LGPD, Lei nº 13.709/2018)."
        :updated-at="$atualizadoEm">

        <section>
            <h2>1. Quem trata seus dados</h2>
            <p>
                O Agente Financeiro é um serviço de <strong>gestão de contas pessoais</strong> que permite registrar
                e consultar seus gastos pela web e pelo Telegram, com apoio de inteligência artificial. O controlador
                dos dados tratados aqui é <strong>{{ $controlador }}</strong>.
            </p>
            <p>
                Tratamos apenas os dados necessários para essa finalidade e sempre <strong>isolados por conta</strong>:
                você só acessa os seus próprios dados, nunca os de outra pessoa.
            </p>
        </section>

        <section>
            <h2>2. Quais dados coletamos</h2>
            <p>Coletamos o mínimo necessário para o serviço funcionar:</p>
            <ul>
                <li><strong>Cadastro:</strong> nome, e-mail e senha (armazenada apenas de forma cifrada).</li>
                <li><strong>Vínculo com o Telegram:</strong> seu identificador do Telegram e o telefone verificado, quando você opta por usar o bot.</li>
                <li><strong>Seus lançamentos financeiros:</strong> valores, datas, categorias e a origem do registro (web, bot ou importação).</li>
                <li><strong>Conversas com o assistente:</strong> as mensagens trocadas com o agente, para dar contexto ao diálogo.</li>
                <li><strong>Metadados de uso da IA:</strong> modelo, quantidade de tokens, custo e tempo de resposta — <strong>sem o conteúdo</strong> das suas mensagens.</li>
            </ul>

            <h3>O que <em>não</em> guardamos</h3>
            <p>
                Por princípio de minimização, alguns dados <strong>nunca</strong> são armazenados:
            </p>
            <ul>
                <li>O <strong>PDF da fatura</strong> e o <strong>texto extraído</strong> dele são processados na hora e <strong>descartados imediatamente</strong> — não ficam guardados.</li>
                <li>Dados sensíveis que possam aparecer na fatura — como <strong>nome, endereço, CPF ou data de nascimento</strong> — são <strong>ignorados por completo</strong>. Só os lançamentos financeiros viram registro.</li>
                <li>Não guardamos comprovantes em imagem.</li>
            </ul>
        </section>

        <section>
            <h2>3. Por que tratamos e com qual base legal</h2>
            <p>
                A finalidade é <strong>única e declarada</strong>: organizar as suas contas pessoais. Não há uso
                secundário — não vendemos seus dados, não fazemos marketing com eles e não cruzamos informações entre
                contas de usuários diferentes.
            </p>
            <p>
                A base legal para tratar seus dados financeiros é o <strong>seu consentimento</strong> (LGPD, art. 7º,
                I), coletado no primeiro acesso e registrado com data e hora. Você pode revogá-lo a qualquer momento
                excluindo sua conta.
            </p>
        </section>

        <section>
            <h2>4. Como a inteligência artificial usa seus dados</h2>
            <div class="legal-callout">
                <x-icon name="cpu" class="mt-0.5 h-6 w-6 shrink-0 text-primary" />
                <p>
                    <strong>A IA nunca calcula nem inventa valores.</strong> Todo número — saldos, parcelas,
                    vencimentos, o disponível do mês — é calculado de forma determinística pelo sistema, a partir dos
                    seus registros. A IA apenas <strong>interpreta</strong> o que você escreve, <strong>classifica</strong>
                    categorias e <strong>redige</strong> as respostas sobre números que já foram calculados.
                </p>
            </div>
            <p>
                Quando usamos um provedor de IA, enviamos a ele <strong>apenas dados não sensíveis</strong> e
                estritamente o necessário para interpretar sua mensagem. As ferramentas que a IA aciona são sempre
                <strong>filtradas pela sua conta</strong>, de modo que ela não tem como acessar dados de outra pessoa.
            </p>
        </section>

        <section>
            <h2>5. Por quanto tempo guardamos</h2>
            <ul>
                <li><strong>PDF e texto extraído da fatura:</strong> retenção zero — descartados ao fim do processamento.</li>
                <li><strong>Conversas com o assistente:</strong> mantidas por até <strong>60 dias</strong> e depois expurgadas automaticamente.</li>
                <li><strong>Cadastro e lançamentos:</strong> enquanto sua conta existir.</li>
                <li><strong>Registros de auditoria:</strong> preservados para segurança e conformidade, sem expor dados sensíveis, mesmo após a exclusão da conta.</li>
            </ul>
        </section>

        <section>
            <h2>6. Com quem compartilhamos</h2>
            <p>
                Compartilhamos dados apenas com os <strong>provedores de infraestrutura e de inteligência artificial</strong>
                estritamente necessários para operar o serviço — e, no caso da IA, somente dados não sensíveis. Não
                compartilhamos, vendemos ou cedemos seus dados para fins de publicidade.
            </p>
        </section>

        <section>
            <h2>7. Como protegemos seus dados</h2>
            <ul>
                <li><strong>Criptografia em trânsito (TLS)</strong> em toda a comunicação.</li>
                <li><strong>Isolamento por usuário:</strong> cada operação valida que o dado pertence a você.</li>
                <li><strong>Segredos protegidos:</strong> chaves e credenciais nunca ficam em código, imagem ou registros.</li>
                <li><strong>Minimização e descarte</strong> do que é sensível e desnecessário.</li>
            </ul>
        </section>

        <section>
            <h2>8. Seus direitos</h2>
            <p>A qualquer momento você pode, quanto aos seus dados (LGPD, art. 18):</p>
            <ul>
                <li><strong>Confirmar e acessar</strong> os dados que tratamos sobre você.</li>
                <li><strong>Corrigir</strong> dados incompletos ou desatualizados.</li>
                <li><strong>Exportar</strong> seus dados em formato estruturado (portabilidade).</li>
                <li><strong>Excluir</strong> seus dados e sua conta. A exclusão é lógica: seus dados pessoais deixam de ser acessíveis, mas mantemos o registro mínimo de auditoria exigido, sem reexpor o que foi apagado.</li>
                <li><strong>Revogar o consentimento</strong>, encerrando o tratamento.</li>
            </ul>
            <p>
                Para exercer esses direitos, use as opções em <strong>Configurações &amp; privacidade</strong> no
                aplicativo ou fale com nosso encarregado (seção 11).
            </p>
        </section>

        <section>
            <h2>9. Cookies e tecnologias</h2>
            <p>
                Usamos apenas o <strong>cookie de sessão</strong> essencial para manter você autenticado. Não usamos
                rastreadores de terceiros para publicidade, e as fontes do site são <strong>hospedadas por nós</strong>
                — nenhum dado seu é enviado a redes externas de fontes ou análise.
            </p>
        </section>

        <section>
            <h2>10. Alterações nesta política</h2>
            <p>
                Podemos atualizar esta política para refletir melhorias no serviço ou exigências legais. Quando isso
                acontecer, alteramos a data de <strong>última atualização</strong> no topo desta página. Mudanças
                relevantes serão comunicadas de forma clara.
            </p>
        </section>

        <section>
            <h2>11. Encarregado e contato</h2>
            <p>
                Para dúvidas ou solicitações sobre seus dados, fale com o encarregado pelo tratamento de dados (DPO):
            </p>
            <ul>
                <li><strong>Encarregado:</strong> {{ $encarregado }}</li>
                <li><strong>Contato:</strong> {{ $contato }}</li>
            </ul>
        </section>

    </x-legal.document>
</x-layouts.guest>
