// ================================================================
// KONEX - SCRIPT DE LOGIN FRONT-END
// ================================================================

document.addEventListener('DOMContentLoaded', () => {
    // Procura o formulário e o botão na sua tela
    const formLogin = document.querySelector('form'); 
    const inputSenha = document.querySelector('input[type="password"]');

    if (formLogin) {
        formLogin.addEventListener('submit', async (e) => {
            e.preventDefault(); // Impede a página de recarregar

            const senhaDigitada = inputSenha.value;

            // Mostra um aviso de carregamento no botão (opcional)
            const btnSubmit = formLogin.querySelector('button');
            const textoOriginal = btnSubmit.innerText;
            btnSubmit.innerText = 'Acessando...';

            try {
                // Envia os dados para o nosso arquivo api.php
                const requisicao = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        acao: 'admin_login', // Esta é a chave que estava faltando!
                        senha: senhaDigitada
                    })
                });

                const resposta = await requisicao.json();

                if (resposta.status === 'sucesso') {
                    // Se a senha estiver correta, salva o token
                    localStorage.setItem('admin_token', resposta.token);
                    
                    // Redireciona para a página de dentro do painel
                    // (Altere 'painel.html' para o nome correto da sua página pós-login, se for diferente)
                    window.location.href = 'painel.html'; 
                } else {
                    // Se a senha estiver errada, mostra o aviso
                    alert('Credenciais inválidas. Tente novamente.');
                    inputSenha.value = ''; // Limpa o campo
                    btnSubmit.innerText = textoOriginal;
                }

            } catch (erro) {
                console.error('Erro ao conectar com a API:', erro);
                alert('Erro de conexão. Verifique o console.');
                btnSubmit.innerText = textoOriginal;
            }
        });
    }
});