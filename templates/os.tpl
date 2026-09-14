{extends file="layouts/backend.tpl"}

{block name="page"}
	<p><a href="{$pluginPageUrl|escape}">{translate key="plugins.generic.ojsbrServices.editor.backToList"}</a></p>
	<h1 class="app__pageHeading">{translate key="plugins.generic.ojsbrServices.editor.osTitle" numero=$numero}</h1>

	{if $flash === 'created' && $numero}
		<p><strong>{translate key="plugins.generic.ojsbrServices.editor.created" numero=$numero}</strong></p>
		{if $flashFaltante}
			<p>{translate key="plugins.generic.ojsbrServices.editor.blockedCredits" faltante=$flashFaltante}</p>
		{/if}
	{/if}

	{if $os}
		<div id="ojsbr-os-detail"
			data-ojsbr-os="{$numero|escape}"
			data-ojsbr-prod="{$os.situacaoProducao|escape}">
			<p>
				<strong>{translate key="plugins.generic.ojsbrServices.editor.status"}:</strong>
				<span id="ojsbr-os-prod">{$os.situacaoProducao|escape}</span>
				{if $os.situacaoFinanceira}
					/ <span id="ojsbr-os-fin">{$os.situacaoFinanceira|escape}</span>
				{/if}
			</p>
			<p id="ojsbr-os-credito" {if !$os.creditoFaltante}style="display:none"{/if}>
				{translate key="plugins.generic.ojsbrServices.editor.blockedCredits" faltante=$os.creditoFaltante}
			</p>
			{if $os.arquivosPendentes}
				<p>{translate key="plugins.generic.ojsbrServices.editor.pendingFiles"}</p>
			{/if}
			<table class="pkpTable" style="width:100%; margin: 1rem 0;">
				<thead>
					<tr>
						<th>{translate key="submission.title"}</th>
						<th>DOI</th>
						<th>{translate key="plugins.generic.ojsbrServices.editor.status"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$os.itens item=item}
						<tr>
							<td>
								{$item.titulo|default:$item.title|escape}
								<div class="pkp_helpers_text_smaller">#{$item.submissionId|escape}</div>
							</td>
							<td>{$item.doi|escape}</td>
							<td class="ojsbr-item-status" data-ojsbr-sid="{$item.submissionId|escape}">
								{$item.status|default:$item.situacaoCodigo|escape}
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		</div>
	{else}
		<p>{translate key="plugins.generic.ojsbrServices.error.badRequest"}</p>
	{/if}

	<script>
	(function () {
		var pollUrl = {$pollUrl|json_encode};
		var root = document.getElementById('ojsbr-os-detail');
		if (!pollUrl || !root) return;
		var numero = root.getAttribute('data-ojsbr-os');
		var timer = null;
		function intervalFor(prod) {
			if (prod === 'ST_OS_PROD_CONCLUIDA' || prod === 'ST_OS_PROD_CANCELADA' || prod === 'CONCLUIDA' || prod === 'CANCELADA') return 0;
			if (prod === 'ST_OS_PROD_BLOQUEADA' || prod === 'ST_OS_FIN_AGUARDANDO_PAGAMENTO' || prod === 'BLOQUEADA' || prod === 'AGUARDANDO_PAGAMENTO') return 300000;
			if (prod === 'ST_OS_PROD_ATENCAO' || prod === 'ATENCAO') return 60000;
			return 30000;
		}
		function tick() {
			if (document.hidden) return;
			var url = pollUrl + (pollUrl.indexOf('?') >= 0 ? '&' : '?') + 'numero=' + encodeURIComponent(numero);
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
				if (!data || !data.ok) return;
				var prodEl = document.getElementById('ojsbr-os-prod');
				var finEl = document.getElementById('ojsbr-os-fin');
				if (prodEl) prodEl.textContent = data.situacaoProducao || '';
				if (finEl) finEl.textContent = data.situacaoFinanceira || '';
				root.setAttribute('data-ojsbr-prod', data.situacaoProducao || '');
				(data.itens || []).forEach(function (it) {
					var cell = root.querySelector('.ojsbr-item-status[data-ojsbr-sid="' + it.submissionId + '"]');
					if (cell) cell.textContent = it.status || it.situacaoCodigo || '';
				});
				var cred = document.getElementById('ojsbr-os-credito');
				if (cred) cred.style.display = data.creditoFaltante ? '' : 'none';
				schedule(data.situacaoProducao);
			}).catch(function () {});
		}
		function schedule(prod) {
			if (timer) clearTimeout(timer);
			var ms = intervalFor(prod);
			if (!ms) return;
			timer = setTimeout(tick, ms);
		}
		tick();
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) return;
			tick();
		});
	})();
	</script>
{/block}
