{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">{translate key="plugins.generic.ojsbrServices.editor.title"}</h1>
	<p>{translate key="plugins.generic.ojsbrServices.editor.intro"}</p>

	{if !$hasSettings}
		<p class="pkp_form_error">{translate key="plugins.generic.ojsbrServices.editor.missingSettings"}</p>
	{/if}

	{if $flash === 'created' && $flashNumero}
		<p><strong>{translate key="plugins.generic.ojsbrServices.editor.created" numero=$flashNumero}</strong></p>
		{if $flashFaltante}
			<p>{translate key="plugins.generic.ojsbrServices.editor.blockedCredits" faltante=$flashFaltante}</p>
		{/if}
	{elseif $flash === 'missingSettings'}
		<p class="pkp_form_error">{translate key="plugins.generic.ojsbrServices.editor.missingSettings"}</p>
	{elseif $flash === 'missingItems'}
		<p class="pkp_form_error">{translate key="plugins.generic.ojsbrServices.editor.missingItems"}</p>
	{elseif $flash === 'signatureFailed'}
		<p class="pkp_form_error">{translate key="plugins.generic.ojsbrServices.editor.signatureFailed"}</p>
	{elseif $flash === 'badRequest'}
		<p class="pkp_form_error">{translate key="plugins.generic.ojsbrServices.error.badRequest"}</p>
	{/if}

	{if $rows|@count}
		<form method="post" action="{$criarUrl|escape}">
			{csrf}
			<table class="pkpTable" style="width:100%; margin: 1rem 0;">
				<thead>
					<tr>
						<th></th>
						<th>{translate key="submission.title"}</th>
						<th>DOI</th>
						<th>{translate key="plugins.generic.ojsbrServices.editor.os"}</th>
						<th>{translate key="plugins.generic.ojsbrServices.editor.status"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$rows item=row}
						<tr>
							<td>
								<input type="checkbox" name="submissionIds[]" value="{$row.submissionId|escape}" id="ojsbr-sub-{$row.submissionId|escape}">
							</td>
							<td>
								<label for="ojsbr-sub-{$row.submissionId|escape}">{$row.title|escape}</label>
								<div class="pkp_helpers_text_smaller">#{$row.submissionId|escape}</div>
							</td>
							<td>{$row.doi|escape}</td>
							<td class="ojsbr-os-numero">{if $row.os}{$row.os.numero|escape}{/if}</td>
							<td
								class="ojsbr-os-status"
								{if $row.os}
									data-ojsbr-os="{$row.os.numero|escape}"
									data-ojsbr-prod="{$row.os.situacaoProducao|escape}"
									data-ojsbr-sid="{$row.submissionId|escape}"
								{/if}
							>
								{if $row.os}
									{$row.os.situacaoProducao|escape}
									{if $row.os.itemStatus} / {$row.os.itemStatus|escape}{/if}
								{/if}
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			<button class="pkpButton" type="submit" {if !$hasSettings}disabled{/if}>
				{translate key="plugins.generic.ojsbrServices.editor.submit"}
			</button>
		</form>

		<form method="post" action="{$statusUrl|escape}" style="margin-top: 1.5rem;">
			{csrf}
			<label>
				{translate key="plugins.generic.ojsbrServices.editor.os"}
				<input type="text" name="numero" required>
			</label>
			<button class="pkpButton pkpButton--isSecondary" type="submit">
				{translate key="plugins.generic.ojsbrServices.editor.refresh"}
			</button>
		</form>
	{else}
		<p>{translate key="plugins.generic.ojsbrServices.editor.noSubmissions"}</p>
	{/if}

	<script>
	(function () {
		var pollUrl = {$pollUrl|json_encode};
		if (!pollUrl) return;
		var cells = document.querySelectorAll('[data-ojsbr-os]');
		if (!cells.length) return;
		var timers = {};
		function intervalFor(prod) {
			if (prod === 'ST_OS_PROD_CONCLUIDA' || prod === 'ST_OS_PROD_CANCELADA' || prod === 'CONCLUIDA' || prod === 'CANCELADA') return 0;
			if (prod === 'ST_OS_PROD_BLOQUEADA' || prod === 'ST_OS_FIN_AGUARDANDO_PAGAMENTO' || prod === 'BLOQUEADA' || prod === 'AGUARDANDO_PAGAMENTO') return 300000;
			if (prod === 'ST_OS_PROD_ATENCAO' || prod === 'ATENCAO') return 60000;
			return 30000;
		}
		function tick(numero) {
			if (document.hidden) return;
			var url = pollUrl + (pollUrl.indexOf('?') >= 0 ? '&' : '?') + 'numero=' + encodeURIComponent(numero);
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
				if (!data || !data.ok) return;
				cells.forEach(function (cell) {
					if (cell.getAttribute('data-ojsbr-os') !== numero) return;
					var sid = cell.getAttribute('data-ojsbr-sid');
					var item = (data.itens || []).find(function (it) { return String(it.submissionId) === String(sid); });
					var status = (data.situacaoProducao || '') + (item && item.status ? ' / ' + item.status : '');
					cell.textContent = status;
					cell.setAttribute('data-ojsbr-prod', data.situacaoProducao || '');
					var numCell = cell.parentNode && cell.parentNode.querySelector('.ojsbr-os-numero');
					if (numCell && data.numero) numCell.textContent = data.numero;
				});
				schedule(numero, data.situacaoProducao);
			}).catch(function () {});
		}
		function schedule(numero, prod) {
			if (timers[numero]) clearTimeout(timers[numero]);
			var ms = intervalFor(prod);
			if (!ms) return;
			timers[numero] = setTimeout(function () { tick(numero); }, ms);
		}
		var seen = {};
		cells.forEach(function (cell) {
			var numero = cell.getAttribute('data-ojsbr-os');
			if (!numero || seen[numero]) return;
			seen[numero] = true;
			schedule(numero, cell.getAttribute('data-ojsbr-prod'));
		});
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) return;
			Object.keys(seen).forEach(function (numero) { tick(numero); });
		});
	})();
	</script>
{/block}
