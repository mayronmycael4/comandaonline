# Guia rapido: APK do Comanda Mobile (Poco X7 Pro)

Este projeto ja esta preparado como app (PWA) e com icones da logo da espetaria:
- `icon-192.png`
- `icon-512.png`

## 1) Melhor caminho para gerar APK agora (PWABuilder)

Requisito: seu sistema deve estar publicado em HTTPS (dominio real).

1. Publique a pasta do projeto no seu servidor HTTPS.
2. Abra https://www.pwabuilder.com/
3. Cole a URL mobile do sistema (exemplo):
   - `https://SEU-DOMINIO/index-mobile.html`
4. Clique em **Build My PWA**.
5. Em **Android**, gere o pacote (APK/AAB).
6. Baixe o arquivo e instale no Poco X7 Pro (ou envie para Play Store).

## 2) Instalar direto como app (sem APK)

No Poco X7 Pro (Chrome):
1. Abra o sistema mobile em HTTPS.
2. Menu do Chrome > **Instalar app**.
3. O app fica com icone e abre em tela cheia, como aplicativo nativo.

## 3) Se quiser APK local sem servidor publico

Use Android Studio com um app WebView apontando para seu servidor local/rede.
Isso exige no PC:
- Java (JDK 17+)
- Android Studio
- Android SDK

## 4) Assinatura para producao

Para distribuir fora da Play Store:
1. Assine o APK no Android Studio (Generate Signed Bundle / APK).
2. Ative no Android do Poco X7 Pro: "Instalar apps desconhecidos" para o instalador usado.

## Observacoes

- O nome do app foi padronizado para "Espetaria" nos manifests.
- A logo da espetaria foi aplicada nos icones 192 e 512.
- Se quiser, no proximo passo eu monto aqui no projeto uma pasta Android (WebView) pronta para compilar no Android Studio.
