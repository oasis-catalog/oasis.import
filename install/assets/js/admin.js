(function(){
	if(BX && BX.UI.FileInput){
		(function (main) {
			main.FileInput = BX.UI.FileInput;
			BX.UI.FileInput = function () {
				let values = arguments[3] || [],
					is = values.some(v => {
						return v.real_url.indexOf('https://') == 0 || v.real_url.indexOf('http://') == 0;
					});

				if(is){
					values.forEach(v => {
						v.preview_url = v.real_url;
					});
					let opt = arguments[2] || {};
					opt.delete = false;
					opt.edit = false;
				}

				main.FileInput.apply(this, arguments);
			};

			BX.UI.FileInput.prototype = main.FileInput.prototype
		}({}));
	}
})();