BX.ready(function () {
	let OaHelper = window.OaHelper || {
		branding: null
	};

	if (!OaHelper.branding || typeof JCCatalogElement === 'undefined') {
		return;
	}

	(function (parent){
		JCCatalogElement.prototype.fillBasketProps = function () {
			parent.apply(this, arguments);

			param.box.find('input[type="hidden"]').each((i, el) => {
				if (el.value) {
					this.basketParams[el.name] = el.value;
				}
			});
		}
	})(JCCatalogElement.prototype.fillBasketProps);

	(function (parent){
		JCCatalogElement.prototype.changeInfo = function () {
			parent.apply(this, arguments);
			changeVariation(this.offers[this.offerNum].ID);
		}
	})(JCCatalogElement.prototype.changeInfo);

	let param = {
		box: $(OaHelper.branding),
		node: null
	};

	function changeVariation(product_id) {
		if (param.node) {
			BX.showWait(param.box.get(0));
		}

		BX.ajax.runAction('oasis:import.api.Branding.get', {
			data: { product_id: product_id }
		}).then(function (response) {
			if (response.status == 'success' && response.data) {
				if (param.node) {
					BX.closeWait(param.box.get(0));
					param.node.remove();
				}

				let cl = 'js--oasis-client-branding-widget';
				param.node = $(`<div class="oasis-client-branding-widget"><div class="${cl}"></div></div>`);
				param.box.append(param.node);

				OasisBrandigWidget('.' + cl, {
					productId: response.data,
					locale: OaHelper.currency ||'ru-RU',
					currency: OaHelper.currency || 'RUB'
				});
			}
		}, function (response) {
		});
	}
});