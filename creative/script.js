// ================================================================
// KONEX - SCRIPT DE LOGIN FRONT-END
// ================================================================

document.addEventListener('DOMContentLoaded', () => {
    // Captura os elementos pelo ID correto do index.html
    const inputSenha = document.getElementById('lSenha');
    const btnLogin = document.querySelector('#formLogin .btn-magic');

    if (!inputSenha || !btnLogin) return;

    // Função principal de login
    async function fazerLogin() {
        const senhaDigitada = inputSenha.value.trim();

        if (!senhaDigitada) {
            alert('⚠️ Digite a senha de acesso.');
            return;
        }

        // Mostra um aviso de carregamento no botão
        const textoOriginal = btnLogin.innerText;
        btnLogin.innerText = 'Acessando...';
        btnLogin.disabled = true;

        try {
            // Envia os dados para o nosso arquivo api.php
            const requisicao = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    acao: 'admin_login',
                    senha: senhaDigitada
                })
            });

            const resposta = await requisicao.json();

            if (resposta.status === 'sucesso') {
                // Se a senha estiver correta, salva o token
                localStorage.setItem('admin_token', resposta.token);

                // Redireciona para a página do painel administrativo
                window.location.href = 'admin.php';
            } else {
                // Se a senha estiver errada, mostra o aviso da API
                alert(resposta.msg || 'Credenciais inválidas. Tente novamente.');
                inputSenha.value = '';
            }

        } catch (erro) {
            console.error('Erro ao conectar com a API:', erro);
            alert('Erro de conexão com o servidor. Verifique o console.');
        } finally {
            btnLogin.innerText = textoOriginal;
            btnLogin.disabled = false;
        }
    }

    // Dispara o login ao clicar no botão
    btnLogin.addEventListener('click', (e) => {
        e.preventDefault();
        fazerLogin();
    });

    // Dispara o login ao pressionar Enter no campo de senha
    inputSenha.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            fazerLogin();
        }
    });
});