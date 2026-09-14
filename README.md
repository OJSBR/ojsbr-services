# OJSBR Services

Plugin genérico PKP (`GenericPlugin`) para o editor criar e acompanhar **ordens de serviço** OJSBR (marcação XML JATS) a partir da revista. Fala **somente** com o conector `node-stnt-ojs`. Não calcula preço, não conhece `clienteId` / `contratoId` e **nunca** assina com a privada Ed25519 da OJSBR (ela não existe neste plugin).

Este repositório segue o padrão PKP: **uma branch por linha de OJS**. A linha **3.5** aplica XML/galley no callback e faz polling na tela do editor.

Instalar em:

```text
plugins/generic/ojsbrServices
```

O core do OJS **não** está neste disco. O PHP usa APIs idiomáticas da 3.5 (`PKPApplication`, `PluginRegistry`, `Hook`, `Handler`, `Role`, `Repo::submission()`, `Publication`). Depois de copiar para uma instalação 3.5, registrar com `lib/pkp/tools/installPluginVersion.php` (ou pela galeria) e habilitar no contexto da revista. Requer `ext-sodium` e `ext-curl`.

## Branches

```text
ojsbr-services
  stable-3_3_0     -- OJS 3.3  (ainda não portado: hooks/DAO)
  stable-3_4_0     -- OJS 3.4  (ainda não portado: hooks/DAO)
  stable-3_5_0     -- OJS 3.5  (esta árvore)
  master           -- cópia da última (3.5); default do clone
```

Release/tag por branch (`1.0.0-3.3`, `1.0.0-3.4`, `1.0.0-3.5`). Feature compartilhada (assinatura, heartbeat, payload do conector) entra primeiro na `stable-3_5_0` / `master` e é portada para as stables anteriores.

A linha 3.5 já cria OS, consulta status, recebe callback assinado (persiste ref **e** aplica galley/XML) e faz polling com a tela aberta. 3.3 e 3.4 portam o mesmo contrato HTTP, trocando só o PHP nativo:

* registro de hooks / menu editorial (`Hook::add` vs `HookRegistry`);
* publication vs submission (3.3 quase só submission; 3.4+ publication corrente);
* listagem de galleys e arquivos (API/DAO daquela linha);
* upload de galley de resultado no callback;
* `authorize()` / roles (Manager / Editor).

A chave pública pinada fica no **mesmo path** em todas as branches: `keys/ojsbr.pub`.

Árvore desta linha (3.5) — copiar o mesmo esqueleto nas branches 3.3/3.4 e ajustar só o PHP nativo:

```text
ojsbr-services/
  version.xml
  OjsbrServicesPlugin.php
  index.php
  settings.xml
  keys/ojsbr.pub
  locale/en/locale.po
  locale/pt_BR/locale.po
  pages/OjsbrServiceHandler.php
  pages/OjsbrEditorHandler.php
  classes/OjsbrHttp.php
  classes/OjsbrSignature.php
  templates/settings.tpl
  templates/editor.tpl
  README.md
```

## Settings

Editáveis pelo gestor da revista (UI do plugin):

| Setting | Uso |
|---|---|
| `ojsbrServices.connectorUrl` | Base do conector, ex. `https://ojs-plugin.ojsbr.com` |
| `ojsbrServices.token` | Token da revista (painel STNT). Bearer / `X-OJSBR-Token` |

A **pública OJSBR não é setting de UI**. Pin inicial em `keys/ojsbr.pub`. Depois da rotação (`…/ojsbr/chave`), o plugin persiste a vigente em settings internos (`ojsbrServices.trustedPublica`, `ojsbrServices.chavePublicaVersao`, `ojsbrServices.chavePublicaDtFim` + pares `*Anterior` para overlap até `dtFim`). O editor não cola outra chave nem desliga a verificação.

Referências de OS por submission ficam em `ojsbrServices.osPorSubmission` (interno).

## URL (page `ojsbr`)

`LoadHandler` registra a page `ojsbr`. `getName()` do plugin **não** entra na URL. O conector sempre monta com `index.php`:

```text
{baseUrl}/index.php/{journalPath}/ojsbr/{op}
```

| op | Handler | Auth |
|---|---|---|
| `heartbeat` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `callback` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `chave` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `index` / `criar` / `status` | `OjsbrEditorHandler` | Manager / Sub-editor + CSRF |

Proibido: `/ojsbr-services/...`, `$$$call$$$` / `ROUTE_COMPONENT`, `manage&verb=` como canal STNT.

## Confiança (STNT → plugin)

Headers em **pedidos** do conector ao plugin e em **respostas** de `GET/POST /plugin/v1/...`:

```text
X-OJSBR-Timestamp: unix seconds
X-OJSBR-Signature: base64(ed25519(timestamp + "\n" + sha256_hex(body)))
```

`sha256_hex` = `hash('sha256', $body)` (hex minúsculo). Recusar se timestamp fora de ±5 min, hash do body não bater ou a assinatura não validar na pública confiável (pin ou persistida; overlap com a anterior até `dtFim`). Sem modo “aceitar sem assinatura”.

O plugin **só verifica**. A privada fica no `stnt-ojs`.

### Heartbeat

Pedido assinado `{ "ts", "nonce" }`. Resposta **200 sem Ed25519**:

```json
{
  "ok": true,
  "chavePublicaVersao": "pin",
  "journalPath": "minha-revista",
  "hmac": "base64(HMAC-SHA256(pluginToken, nonce))"
}
```

### Callback

Pedido assinado com status, `artefatos[]` (`contentBase64`) e metadados. Persiste a referência da OS e tenta aplicar XML/galley na publication corrente (`OjsbrGalleyApplier`).

### Chave

Pedido assinado `{ versao, publica, dtFim }`. Persiste a pública e devolve `{ versao, hmac }` (HMAC do token com `nonce` do body ou, se ausente, com `versao`). Sem Ed25519 na resposta.

## Plugin → conector

`Authorization: Bearer {token}` (e `X-OJSBR-Token`). O plugin **verifica** a assinatura da resposta.

```text
POST {connectorUrl}/plugin/v1/ordens
GET  {connectorUrl}/plugin/v1/ordens/:numero
POST {connectorUrl}/plugin/v1/ordens/:numero/itens/:submissionId/arquivos
```

Create JSON (sem preço, sem bytes):

```json
{
  "service": "OS_JATS_XML",
  "ojsVersion": "3.5",
  "journalPath": "minha-revista",
  "journal": {
    "title": "",
    "acronym": "",
    "issnPrint": "",
    "issnOnline": "",
    "publisher": "",
    "locales": ["pt_BR", "en"],
    "metadata": {}
  },
  "items": [
    {
      "submissionId": "123",
      "publicationId": "456",
      "title": "",
      "doi": "",
      "locale": "pt_BR",
      "metadata": {},
      "galleys": [
        { "id": "10", "label": "PDF", "locale": "pt_BR", "genre": "galley", "fileName": "artigo.pdf" }
      ],
      "files": [
        { "role": "pdf_final", "fileName": "artigo.pdf", "locale": "pt_BR" },
        { "role": "manuscrito", "fileName": "artigo.docx" },
        { "role": "galley", "galleyId": "10", "fileName": "artigo.pdf", "locale": "pt_BR" }
      ]
    }
  ]
}
```

Depois do `numero`, sobe arquivos **em série** (um submission por vez), multipart com `role` (`pdf_final` | `manuscrito` | `galley`) e `galleyId` quando couber. Timeout 120 s, corpo 32 MiB.

Se a OS nascer bloqueada por crédito: a UI mostra `creditoFaltante` e o texto de regularização da OJSBR — sem boleto/PIX.

UI do editor: `{baseUrl}/index.php/{journalPath}/ojsbr` (também no atalho das ações do plugin).
