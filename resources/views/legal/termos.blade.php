@php
    $foro = config('legal.foro');
    $contato = config('legal.contato');
    $atualizadoEm = config('legal.termos_atualizado_em');
@endphp

<x-layouts.guest title="Termos de Uso | Agente Financeiro"
    description="As regras de uso do Agente Financeiro: sua conta, o papel da IA e suas responsabilidades.">

    <x-legal.document current="terms" title="Termos de Uso"
        subtitle="As regras para usar o Agente Financeiro. Ao criar uma conta, você concorda com estes termos."
        :updated-at="$atualizadoEm">

        <section>
            <h2>1. Aceitação dos termos</h2>
            <p>
                Ao criar uma conta e usar o Agente Financeiro, você declara ter lido e concordado com estes Termos de
                Uso e com a <a href="{{ route('privacy') }}">Política de Privacidade</a>. Se não concordar com algum
                ponto, não utilize o serviço.
            </p>
        </section>

        <section>
            <h2>2. O que é o Agente Financeiro</h2>
            <p>
                O Agente Financeiro é uma ferramenta de <strong>gestão de contas pessoais</strong>. Ele ajuda você a
                registrar e consultar seus gastos pela web e pelo Telegram, com apoio de inteligência artificial para
                interpretar mensagens, classificar categorias e redigir resumos. É uma ferramenta de organização
                pessoal — <strong>não</strong> é um serviço bancário, contábil ou de investimentos.
            </p>
        </section>

        <section>
            <h2>3. Sua conta</h2>
            <ul>
                <li>Você é responsável por manter a <strong>confidencialidade da sua senha</strong> e por toda atividade feita na sua conta.</li>
                <li>Os dados de cadastro devem ser <strong>verdadeiros e atualizados</strong>.</li>
                <li>A conta é de <strong>uso pessoal</strong>: os dados que você registra dizem respeito às suas próprias contas.</li>
            </ul>
        </section>

        <section>
            <h2>4. Uso aceitável</h2>
            <p>Ao usar o serviço, você concorda em <strong>não</strong>:</p>
            <ul>
                <li>Usá-lo para fins ilícitos ou para registrar dados de terceiros sem autorização.</li>
                <li>Tentar acessar dados de outras contas ou burlar o isolamento entre usuários.</li>
                <li>Tentar manipular, enganar ou extrair as instruções do assistente de IA, ou usá-lo fora da finalidade de gestão financeira pessoal.</li>
                <li>Sobrecarregar, interromper ou comprometer a segurança e a disponibilidade do serviço.</li>
            </ul>
        </section>

        <section>
            <h2>5. O papel da inteligência artificial e seus limites</h2>
            <div class="legal-callout">
                <x-icon name="cpu" class="mt-0.5 h-6 w-6 shrink-0 text-primary" />
                <p>
                    <strong>A IA nunca calcula nem decide valores.</strong> Todo número é calculado de forma
                    determinística pelo sistema a partir dos seus registros. A IA apenas interpreta, classifica e
                    redige respostas sobre números já calculados.
                </p>
            </div>
            <p>
                O Agente Financeiro <strong>não presta aconselhamento financeiro, contábil, jurídico ou de
                investimentos</strong>. As respostas do assistente são um apoio à organização das suas contas e não
                substituem a orientação de um profissional.
            </p>
            <p>
                <strong>Nenhum registro é gravado sem a sua confirmação.</strong> Antes de salvar ou editar um
                lançamento, o sistema mostra uma prévia e pede que você confirme — a conferência final é sua
                responsabilidade.
            </p>
        </section>

        <section>
            <h2>6. Seus dados e privacidade</h2>
            <p>
                O tratamento dos seus dados é regido pela nossa <a href="{{ route('privacy') }}">Política de
                Privacidade</a>, que detalha o que coletamos, por quanto tempo guardamos e como você exerce seus
                direitos sob a LGPD.
            </p>
        </section>

        <section>
            <h2>7. Disponibilidade do serviço</h2>
            <p>
                Trabalhamos para manter o serviço disponível e correto, mas ele é oferecido <strong>no estado em que se
                encontra</strong>. Pode haver interrupções para manutenção, e recursos de IA ou de integração com o
                Telegram podem, eventualmente, ficar temporariamente indisponíveis. Nesses casos, buscamos degradar de
                forma segura, sem gravar nada sem a sua confirmação.
            </p>
        </section>

        <section>
            <h2>8. Limitação de responsabilidade</h2>
            <p>
                O Agente Financeiro é uma ferramenta de apoio. Na medida permitida pela lei, não nos responsabilizamos
                por decisões que você tome com base nas informações organizadas na plataforma. A conferência dos seus
                lançamentos e das suas contas é sua responsabilidade.
            </p>
        </section>

        <section>
            <h2>9. Propriedade intelectual</h2>
            <p>
                O software, a marca, o design e os conteúdos do Agente Financeiro pertencem aos seus titulares. Os
                <strong>dados que você registra são seus</strong> — você pode exportá-los ou excluí-los a qualquer
                momento, conforme a Política de Privacidade.
            </p>
        </section>

        <section>
            <h2>10. Encerramento e exclusão de conta</h2>
            <p>
                Você pode encerrar sua conta quando quiser, em <strong>Configurações &amp; privacidade</strong>. A
                exclusão remove o acesso aos seus dados pessoais, preservando apenas o registro mínimo de auditoria
                exigido. Podemos suspender contas que violem estes termos.
            </p>
        </section>

        <section>
            <h2>11. Alterações nestes termos</h2>
            <p>
                Podemos atualizar estes termos ao longo do tempo. Quando isso acontecer, alteramos a data de
                <strong>última atualização</strong> no topo desta página e comunicamos mudanças relevantes.
            </p>
        </section>

        <section>
            <h2>12. Lei aplicável e foro</h2>
            <p>
                Estes termos são regidos pela legislação brasileira. Fica eleito o foro de <strong>{{ $foro }}</strong>
                para dirimir eventuais questões, salvo disposição legal em contrário que assegure foro ao consumidor.
            </p>
        </section>

        <section>
            <h2>13. Contato</h2>
            <p>Dúvidas sobre estes termos? Fale com a gente em <strong>{{ $contato }}</strong>.</p>
        </section>

    </x-legal.document>
</x-layouts.guest>
