jQuery(function ($) {
	let tree = new OasisHelper.Tree('#oa-tree', {
		onBtnRelation (cat_id, cat_rel_id){
			ModalRelation(cat_rel_id).then(item => tree.setRelationItem(cat_id, item));
		}
	});

	$('#stocks').on('change', function() {
		if (this.checked) {
			BX.adjust(BX('remote_stock'), {style: {display: "table-row"}});
			BX.adjust(BX('europe_stock'), {style: {display: "table-row"}});
		} else {
			BX.adjust(BX('remote_stock'), {style: {display: "none"}});
			BX.adjust(BX('europe_stock'), {style: {display: "none"}});
		}
	});


	$('#cf_opt_category_rel').on('click', function(){
		let el_value = $(this).find('input[type="hidden"]'),
			el_label = $(this).find('.oa-category-rel'),
			cat_rel_id = el_value.val();

		cat_rel_id = cat_rel_id ? parseInt(cat_rel_id) : null;

		ModalRelation(cat_rel_id).then(item => {
			el_value.val(item ? item.value : '');
			el_label.text(item ? item.lebelPath : '');
		});
	});


	function ModalRelation(cat_rel_id){ // int or null
		let isSetOldRel = cat_rel_id !== null;

		return new Promise((resolve, reject) => {
			let tree,
				btns = [
					new BX.PopupWindowButton({
						text: 'Сохранить',
						className: 'ui-btn ui-btn-xs ui-btn-success' + (!isSetOldRel ? ' ui-btn-disabled' : ''),
						events: {
							click: function(){
								let item = tree.item;
								if(item){
									this.popupWindow.close();
									this.popupWindow.destroy();
									resolve(item);
								}
							}
						}
					})
				];
			if(isSetOldRel){
				btns.unshift(new BX.PopupWindowButton({
					text: "Очистить" ,
					className: "ui-btn ui-btn-xs" ,
					events: {
						click: function(){
							this.popupWindow.close();
							this.popupWindow.destroy();
							resolve(null);
						}
					}
				}));
			}

			let popup = new BX.PopupWindow(null, window.body, {
				content: '...',
				autoHide : true,
				offsetLeft: 0,
				offsetTop: 0,
				overlay : true,
				titleBar: true,
				closeIcon : true,
				closeByEsc : true,
				buttons: btns
			});
			popup.show();

			$.get('', {
				action: 'getTreeRelation',
			}, tree_content => {
				popup.setContent(tree_content);

				tree = new OasisHelper.RadioTree(popup.getContentContainer().querySelector('.oa-tree'), {
					onChange: item => {
						popup.buttons[0].buttonNode.classList.toggle('ui-btn-disabled', !item);
					},
					onSize: () => {
						popup.adjustPosition();
					}
				});
				tree.value = cat_rel_id;
				popup.adjustPosition();
			});
		});
	}
});