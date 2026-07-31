
/* ======================================================================
 * MÓDULO: CAPTURA/UPLOAD (câmara e ficheiros)
 * Plugins: singleCapture, multipleCapture, uploadCapture
 * ====================================================================== */

/**
 * UploadCaptureCore - Núcleo partilhado por singleCapture e multipleCapture
 *
 * Concentra tudo o que NÃO depende de haver 1 ou N ficheiros: detecção de
 * tipo/mime a partir de um File nativo ou de um data URI, o ícone genérico
 * de documento, e o lightbox de pré-visualização (imagem/pdf/vídeo/download).
 * Isto existe para que singleCapture e multipleCapture ofereçam sempre as
 * mesmas funcionalidades — a única diferença entre eles continua a ser
 * 1 ficheiro vs N, nunca o conjunto de funcionalidades disponível. Qualquer
 * melhoria feita aqui (ex: suporte a mais um tipo de ficheiro na preview)
 * passa a valer nos dois automaticamente, sem editar em dois sítios.
 *
 * O lightbox é um singleton de página: só existe UM, criado na primeira
 * vez que é pedido, partilhado por todos os campos singleCapture/
 * multipleCapture da página (só é possível ver uma preview de cada vez,
 * por isso não há necessidade de um por instância). Não é destruído
 * quando um campo individual é destruído — fica disponível para os
 * restantes campos ainda presentes na página.
 */
var UploadCaptureCore = (function ($) {

    function detectFileMeta(src, file) {
        var isBase64 = src && src.indexOf('data:') === 0;
        // O mime vem do File nativo quando existe (upload/drop); senão é
        // extraído do próprio prefixo do data URI (captura de câmara: image/jpeg).
        var mimeFromSrc = isBase64 ? (src.match(/^data:([^;]+);base64,/) || [])[1] : null;
        var mime = (file && file.type) || mimeFromSrc || '';
        var name = (file && file.name) || '';
        // Sem mime conhecido (ex: path já existente vindo de defaultImage(s)/
        // setImage(s)), assume-se imagem — mantém o comportamento histórico.
        var isImage = mime ? mime.indexOf('image/') === 0 : true;
        return { isBase64: isBase64, mime: mime, name: name, isImage: isImage };
    }

    function docIconHtml(size, label) {
        return '' +
            '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="#6c757d" stroke-width="1.5">' +
            '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
            '<polyline points="14 2 14 8 20 8"/>' +
            '</svg>' +
            '<div style="font-size:10px; color:#6c757d; word-break:break-all; line-height:1.2; max-height:2.4em; overflow:hidden; margin-top:4px;">' + (label || 'FICHEIRO') + '</div>';
    }

    var _lightbox = null;

    function getLightbox() {
        if (_lightbox) return _lightbox;

        var DEFAULT_BOX_CSS = { 'width': '600px', 'height': 'auto', 'max-width': '90vw', 'max-height': '90vh' };

        var $backdrop = $('<div class="upload-preview-backdrop"></div>').css({
            'position': 'fixed', 'top': 0, 'left': 0, 'right': 0, 'bottom': 0,
            'background': 'rgba(0,0,0,0.7)', 'z-index': 10001, 'display': 'none',
            'align-items': 'center', 'justify-content': 'center',
            'padding': '20px', 'box-sizing': 'border-box'
        });

        var $box = $('<div class="upload-preview-box"></div>').css($.extend({
            'position': 'relative', 'background': '#fff', 'border-radius': '8px',
            'display': 'flex', 'flex-direction': 'column', 'overflow': 'hidden',
            'box-shadow': '0 4px 30px rgba(0,0,0,0.4)', 'box-sizing': 'border-box'
        }, DEFAULT_BOX_CSS));

        var $header = $('<div class="upload-preview-header"></div>').css({
            'display': 'flex', 'align-items': 'center', 'padding': '10px 14px',
            'border-bottom': '1px solid #e0e0e0', 'background': '#fafafa',
            'gap': '10px', 'box-sizing': 'border-box', 'flex-shrink': 0
        });

        var $title = $('<div class="upload-preview-title"></div>').css({
            'font-size': '13px', 'color': '#333', 'white-space': 'nowrap',
            'overflow': 'hidden', 'text-overflow': 'ellipsis', 'flex': '1'
        });

        var $downloadBtn = $('<a class="upload-preview-download"></a>').css({
            'display': 'flex', 'align-items': 'center', 'gap': '5px',
            'padding': '6px 12px', 'border-radius': '5px', 'background': '#007bff',
            'color': '#fff', 'font-size': '12px', 'text-decoration': 'none',
            'white-space': 'nowrap', 'cursor': 'pointer', 'flex-shrink': 0
        }).html(
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">' +
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>' +
            '</svg> Descarregar'
        ).hover(
            function () { $(this).css('background', '#0069d9'); },
            function () { $(this).css('background', '#007bff'); }
        );

        var $closeBtn = $('<div class="upload-preview-close"></div>').css({
            'width': '26px', 'height': '26px', 'border-radius': '4px',
            'display': 'flex', 'align-items': 'center', 'justify-content': 'center',
            'cursor': 'pointer', 'color': '#666', 'font-size': '15px', 'flex-shrink': 0
        }).html('✕').hover(
            function () { $(this).css('background', '#eee'); },
            function () { $(this).css('background', 'transparent'); }
        );

        var $body = $('<div class="upload-preview-body"></div>').css({
            'flex': '1', 'overflow': 'auto', 'display': 'flex',
            'align-items': 'center', 'justify-content': 'center',
            'background': '#f1f3f5', 'min-height': '200px'
        });

        $header.append($title, $downloadBtn, $closeBtn);
        $box.append($header, $body);
        $backdrop.append($box);
        $('body').append($backdrop);

        function open(item) {
            $title.text(item.name || (item.isImage ? 'Imagem' : 'Documento'));
            $body.empty();
            $box.css(DEFAULT_BOX_CSS); // repõe tamanho por omissão antes de decidir o desta preview

            var extFromMime = (item.mime || '').split('/').pop();
            var downloadName = item.name || (item.isImage ? 'imagem.' + (extFromMime || 'jpg') : 'ficheiro');
            $downloadBtn.attr({ href: item.src, download: downloadName });

            if (item.isImage) {
                // Clique alterna entre "caber na caixa" e "tamanho real com scroll" — um
                // zoom simples. Um zoom contínuo (roda do rato / pinch) seria bem mais
                // trabalho para o ganho real aqui; isto cobre o caso de querer ver detalhe.
                $('<img>').attr('src', item.src).css({
                    'max-width': '100%', 'max-height': '80vh', 'display': 'block', 'cursor': 'zoom-in'
                }).on('click', function () {
                    var $this = $(this);
                    if ($this.data('zoomed')) {
                        $this.css({ 'max-width': '100%', 'max-height': '80vh', 'cursor': 'zoom-in' }).data('zoomed', false);
                    } else {
                        $this.css({ 'max-width': 'none', 'max-height': 'none', 'cursor': 'zoom-out' }).data('zoomed', true);
                    }
                }).appendTo($body);
            } else if (item.mime === 'application/pdf' || /\.pdf$/i.test(item.name || '')) {
                // PDF aproveita muito mais espaço do que a caixa por omissão —
                // não tem relação nenhuma com estar dentro de um modal Bootstrap;
                // o lightbox está anexado directamente ao body, acima de tudo.
                $box.css({ 'width': '90vw', 'height': '90vh', 'max-width': '1100px', 'max-height': '95vh' });
                $('<iframe></iframe>').attr('src', item.src).css({
                    'width': '100%', 'height': '100%', 'border': 'none', 'background': '#fff'
                }).appendTo($body);
            } else if (item.mime && item.mime.indexOf('video/') === 0) {
                $('<video controls></video>').attr('src', item.src).css({
                    'max-width': '100%', 'max-height': '80vh', 'display': 'block', 'background': '#000'
                }).appendTo($body);
            } else {
                // doc/docx e outros tipos que nenhum browser sabe mostrar inline —
                // aviso claro em vez de uma preview que ficaria em branco. O download
                // (acima) funciona sempre, independentemente do tipo.
                $('<div></div>').css({
                    'text-align': 'center', 'color': '#6c757d', 'padding': '40px 20px', 'font-size': '13px'
                }).html(docIconHtml(40, '') + '<div style="margin-top:10px;">Este tipo de ficheiro não tem pré-visualização.<br>Usa o botão "Descarregar" para o abrir.</div>')
                    .appendTo($body);
            }

            $backdrop.css('display', 'flex');
        }

        function close() {
            $backdrop.css('display', 'none');
            $body.empty();
        }

        $closeBtn.on('click', close);
        $backdrop.on('click', function (e) {
            if (e.target === this) close(); // só fecha se o clique foi no fundo, não na caixa
        });

        _lightbox = { open: open, close: close };
        return _lightbox;
    }

    return {
        detectFileMeta: detectFileMeta,
        docIconHtml: docIconHtml,
        getLightbox: getLightbox
    };

})(jQuery);

/**
 * singleCapture - Plugin para captura de UMA única imagem
 *
 * Depende de: nada entre os plugins deste arquivo (usa apenas APIs
 *             nativas do navegador: getUserMedia, FileReader). Pode ser
 *             usado isoladamente.
 * 
 * @param {Object} options - Opções de configuração
 * @param {string} options.defaultImage - URL da imagem padrão
 * @param {number} options.height - Altura do container em pixels
 * @param {string} options.width - Largura do container (ex: '100%', '300px')
 * @param {number} options.quality - Qualidade da imagem (0.0 a 1.0)
 * @param {string} options.accept - Atributo accept do input de ficheiro. Sem restrição por omissão (aceita qualquer tipo — a validação de tipo é feita no servidor). Só filtra se definido explicitamente. Ex: 'image/*,application/pdf,.doc,.docx'
 * @param {string} options.preferredCamera - 'front', 'back' ou 'default'
 * @param {boolean} options.showCameraSelection - Mostrar seletor de câmaras
 * @param {Object} options.buttonText - Textos personalizados para botões
 * @param {Object} options.messages - Mensagens personalizadas
 * @param {Function} options.onChange - Callback quando imagem muda
 * @param {Function} options.onRemove - Callback quando imagem é removida
 * @param {Function} options.onReady - Callback quando plugin está pronto
 * 
 * @example
 * $('input[name="avatar"]').singleCapture({
 *     height: 150,
 *     defaultImage: '/uploads/avatar.jpg',
 *     onChange: function(base64) {
 *         console.log('Foto capturada');
 *     }
 * });
 */
(function ($) {
    $.fn.singleCapture = function (options) {
        // Se for string, é um comando
        if (typeof options === 'string') {
            var args = Array.prototype.slice.call(arguments, 1);
            this.each(function () {
                var instance = $(this).data('singleCapture');
                if (instance && typeof instance[options] === 'function') {
                    instance[options].apply(instance, args);
                }
            });
            return this;
        }
        var settings = $.extend({
            defaultImage: null,
            height: 200,
            width: '100%',
            quality: 0.8,
            accept: null, // sem restrição por omissão — validação de tipo é responsabilidade do servidor, não do cliente
            buttonText: {
                camera: '',
                upload: '',
                change: '',
                remove: ''
            },
            messages: {
                default: 'Clique ou arraste para adicionar foto'
            },
            onChange: null,
            onRemove: null,
            onReady: null
        }, options);

        return this.each(function () {
            var $original = $(this); // Guarda o input original
            var inputName = $original.attr('name') || 'foto_' + Date.now();
            var images = [];
            var stream = null;

            // Tamanhos responsivos
            var baseSize = Math.min(settings.height, 250);
            var iconSize = Math.min(40, Math.max(24, Math.floor(baseSize * 0.25)));
            var fontSize = Math.min(14, Math.max(11, Math.floor(baseSize * 0.08)));
            var padding = Math.min(12, Math.max(5, Math.floor(baseSize * 0.06)));
            var gap = Math.min(15, Math.max(5, Math.floor(baseSize * 0.04)));

            // Detectar mobile
            var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

            // Input file — sem atributo accept quando settings.accept não é definido,
            // logo sem filtro nenhum: o selector do SO mostra TODOS os tipos de ficheiro.
            // Só filtra se o campo específico passar accept explicitamente na configuração.
            // 'capture' NÃO é definido aqui — é alternado por clique (Câmara põe,
            // Upload remove), porque os dois botões partilham este mesmo $fileInput
            // e precisam de comportamento diferente no mobile.
            var $fileInput = $('<input type="file">').css('display', 'none');
            if (settings.accept) $fileInput.attr('accept', settings.accept);

            // Container principal
            var $wrapper = $('<div class="single-capture-wrapper"></div>').css({
                'position': 'relative',
                'width': settings.width,
                'height': settings.height + 'px',
                'border': '2px dashed #e0e0e0',
                'border-radius': '8px',
                'background': '#fafafa',
                'overflow': 'hidden',
                'cursor': 'pointer',
                'transition': 'all 0.3s ease',
                'box-sizing': 'border-box'
            });

            // Preview
            var $preview = $('<div class="single-capture-preview"></div>').css({
                'position': 'absolute',
                'top': 0,
                'left': 0,
                'width': '100%',
                'height': '100%',
                'background-size': 'cover',
                'background-position': 'center',
                'z-index': 1
            });

            // Texto vazio
            var $emptyText = $('<div class="single-capture-empty"></div>').css({
                'position': 'absolute',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'text-align': 'center',
                'color': '#999',
                'z-index': 2,
                'width': '100%',
                'padding': '10px',
                'box-sizing': 'border-box',
                'pointer-events': 'none',
                'font-size': fontSize + 'px'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5" style="display:block; margin:0 auto;">
                    <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                    <circle cx="12" cy="12" r="4"/>
                </svg>
                <div style="margin-top:${gap}px;">${settings.messages.default}</div>
            `);

            // ===== BOTÃO DE REMOÇÃO (X) COM MESMO ESTILO DOS ÍCONES =====
            var $removeBtn = $('<div class="single-capture-remove"></div>').css({
                'position': 'absolute',
                'top': '8px',
                'right': '8px',
                'width': '24px',
                'height': '24px',
                'border-radius': '4px',  /* Mesmo border-radius dos ícones */
                'border': '2px dashed #dc3545',  /* Borda tracejada vermelha */
                'background': 'rgba(220,53,69,0.15)',  /* Fundo transparente como os ícones */
                'color': '#dc3545',
                'display': 'none',
                'align-items': 'center',
                'justify-content': 'center',
                'cursor': 'pointer',
                'font-size': '14px',
                'font-weight': 'bold',
                'z-index': 20,
                'transition': 'all 0.3s ease',
                'backdrop-filter': 'blur(2px)',
                'box-sizing': 'border-box'
            }).html('✕');

            // Hover effect do botão remover (igual aos ícones)
            $removeBtn.hover(
                function () {
                    $(this).css({
                        'border': '2px solid #dc3545',
                        'background': 'rgba(220,53,69,0.3)',
                        'transform': 'scale(1.05)',
                        'color': '#fff'
                    });
                },
                function () {
                    $(this).css({
                        'border': '2px dashed #dc3545',
                        'background': 'rgba(220,53,69,0.15)',
                        'transform': 'scale(1)',
                        'color': '#dc3545'
                    });
                }
            );

            // Aviso visual (sem lógica própria) mostrado ao passar o rato QUANDO JÁ HÁ
            // ficheiro carregado — só para confirmar que clicar em qualquer ponto do
            // ficheiro abre a preview, não só o centro. pointer-events:none é
            // propositado: este elemento nunca deve apanhar cliques, só ser visto; quem
            // continua a apanhar o clique é sempre $overlay, por baixo dele.
            var $previewHint = $('<div class="single-capture-preview-hint"></div>').css({
                'position': 'absolute',
                'top': 0, 'left': 0, 'width': '100%', 'height': '100%',
                'background': 'rgba(0,0,0,0.35)',
                'display': 'flex',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '6px',
                'opacity': 0,
                'transition': 'opacity 0.2s ease',
                'z-index': 5,
                'pointer-events': 'none',
                'color': '#fff',
                'font-size': fontSize + 'px'
            }).html(`
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span>Ver</span>
            `);

            // Overlay com ícones elegantes
            var $overlay = $('<div class="single-capture-overlay"></div>').css({
                'position': 'absolute',
                'top': 0,
                'left': 0,
                'width': '100%',
                'height': '100%',
                'background': 'rgba(0,0,0,0.6)',
                'display': 'flex',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': gap + 'px',
                'opacity': 0,
                'transition': 'opacity 0.3s ease',
                'z-index': 10,
                'backdrop-filter': 'blur(2px)',
                'pointer-events': 'none'
            });

            // Ícone upload com borda tracejada
            var $uploadIcon = $('<div class="overlay-icon upload-icon"></div>').css({
                'display': 'flex',
                'flex-direction': 'column',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '4px',
                'cursor': 'pointer',
                'padding': padding + 'px',
                'border-radius': '12px',
                'border': '2px dashed white',
                'background': 'rgba(255,255,255,0.15)',
                'min-width': Math.max(60, iconSize + 20) + 'px',
                'transition': 'all 0.3s ease',
                'pointer-events': 'auto',
                'box-sizing': 'border-box'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <div style="font-size:${fontSize}px; color:white;">${settings.buttonText.upload}</div>
            `);

            // Ícone câmara com borda tracejada
            var $cameraIcon = $('<div class="overlay-icon camera-icon"></div>').css({
                'display': 'flex',
                'flex-direction': 'column',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '4px',
                'cursor': 'pointer',
                'padding': padding + 'px',
                'border-radius': '12px',
                'border': '2px dashed white',
                'background': 'rgba(255,255,255,0.15)',
                'min-width': Math.max(60, iconSize + 20) + 'px',
                'transition': 'all 0.3s ease',
                'pointer-events': 'auto',
                'box-sizing': 'border-box'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
                <div style="font-size:${fontSize}px; color:white;">${settings.buttonText.camera}</div>
            `);

            // Hover effects
            $uploadIcon.hover(
                function () { $(this).css({ 'border': '2px solid white', 'background': 'rgba(255,255,255,0.3)', 'transform': 'scale(1.05)' }); },
                function () { $(this).css({ 'border': '2px dashed white', 'background': 'rgba(255,255,255,0.15)', 'transform': 'scale(1)' }); }
            );

            $cameraIcon.hover(
                function () { $(this).css({ 'border': '2px solid white', 'background': 'rgba(255,255,255,0.3)', 'transform': 'scale(1.05)' }); },
                function () { $(this).css({ 'border': '2px dashed white', 'background': 'rgba(255,255,255,0.15)', 'transform': 'scale(1)' }); }
            );

            $overlay.append($cameraIcon, $uploadIcon);

            // Hidden input
            // var $hiddenInput = $('<input type="hidden">').attr('name', inputName);

            // Montagem
            $wrapper.append($preview, $emptyText, $previewHint, $overlay, $removeBtn, $fileInput);
            $original.after($wrapper);
            $original.hide();

            // Webcam container
            var $videoContainer = $('<div class="webcam-container"></div>').css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'z-index': 10000,
                'background': '#000',
                'border-radius': '8px',
                'overflow': 'hidden',
                'box-shadow': '0 0 20px rgba(0,0,0,0.5)',
                'display': 'none',
                'width': '90%',
                'max-width': '640px'
            });

            var $video = $('<video autoplay></video>').css({
                'width': '100%',
                'height': 'auto',
                'display': 'block'
            });

            var $canvas = $('<canvas></canvas>').css('display', 'none');

            var $videoControls = $('<div></div>').css({
                'padding': '10px',
                'background': '#333',
                'text-align': 'center'
            });

            var $captureBtn = $('<button type="button"></button>').css({
                'padding': '8px 20px',
                'border': 'none',
                'border-radius': '5px',
                'background': '#28a745',
                'color': '#fff',
                'cursor': 'pointer',
                'margin-right': '10px',
                'font-size': '14px'
            }).html('📸 Capturar');

            var $closeVideoBtn = $('<button type="button"></button>').css({
                'padding': '8px 20px',
                'border': 'none',
                'border-radius': '5px',
                'background': '#dc3545',
                'color': '#fff',
                'cursor': 'pointer',
                'font-size': '14px'
            }).html('✕ Fechar');

            $videoControls.append($captureBtn, $closeVideoBtn);
            $videoContainer.append($video, $canvas, $videoControls);
            $('body').append($videoContainer);

            // Funções

            function addImage(src, file) {
                var meta = UploadCaptureCore.detectFileMeta(src, file);
                images = [{ src: src, file: file, isBase64: meta.isBase64, mime: meta.mime, name: meta.name, isImage: meta.isImage }];

                if (meta.isImage) {
                    $preview.html('').css('background-image', 'url(' + src + ')');
                } else {
                    // Sem preview possível via background-image para um documento —
                    // mesmo ícone genérico usado em multipleCapture, para ficar
                    // consistente entre os dois.
                    $preview.css('background-image', 'none').css({
                        'display': 'flex', 'flex-direction': 'column',
                        'align-items': 'center', 'justify-content': 'center'
                    }).html(UploadCaptureCore.docIconHtml(Math.min(40, iconSize + 8), meta.name ? meta.name.split('.').pop().toUpperCase() : ''));
                }

                $emptyText.hide();
                $removeBtn.css('display', 'flex');

                if (meta.isBase64) {
                    // ALTERAR O PRÓPRIO INPUT DE file PARA text
                    $original.attr('type', 'text').val(src)
                        .prop('disabled', false); // Garantir que não está disabled
                } else {
                    // VOLTAR PARA file
                    $original.attr('type', 'file').val('');
                }

                if (settings.onChange) settings.onChange(images);
            }

            function removeImage() {
                images = [];
                $preview.html('').css({ 'background-image': 'none', 'display': 'block' });
                $emptyText.show();
                $removeBtn.hide();
                $overlay.css('opacity', 0); // segurança: garante que não fica visível ao remover
                $previewHint.css('opacity', 0); // idem

                // VOLTAR PARA file E LIMPAR
                $original.attr('type', 'file').val('');

                if (settings.onRemove) settings.onRemove();
            }

            function iniciarWebcam() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    alert('A câmara só funciona em ligações seguras (HTTPS). Por favor use upload de ficheiro.');
                    return;
                }
                navigator.mediaDevices.getUserMedia({ video: true, audio: false })
                    .then(function (s) {
                        stream = s;
                        $video[0].srcObject = stream;
                        $videoContainer.show();
                    })
                    .catch(function (err) {
                        alert('Erro ao aceder câmara: ' + err.message);
                    });
            }

            function pararWebcam() {
                if (stream) {
                    stream.getTracks().forEach(t => t.stop());
                    stream = null;
                }
                $videoContainer.hide();
            }

            // Eventos
            // O overlay (câmara/upload) só aparece ao passar o rato QUANDO O CONTAINER
            // ESTÁ VAZIO. Com um ficheiro já carregado, o hover não o revela — assim
            // não tapa o que já lá está, e o clique chega directo à preview (ver handler
            // de $overlay mais abaixo, que continua a funcionar: fica sempre presente no
            // DOM a apanhar cliques, só deixa de aparecer visualmente). Remover o
            // ficheiro (botão ✕) volta a images.length a 0, o que já reactiva o hover
            // sozinho — não precisa de nenhum código extra além desta condição.
            // Com ficheiro carregado, mostra-se $previewHint em vez do overlay — é a
            // condição inversa, nunca as duas ao mesmo tempo.
            $wrapper.on('mouseenter', function () {
                if (images.length === 0) {
                    $overlay.css('opacity', 1);
                } else {
                    $previewHint.css('opacity', 1);
                }
            });
            $wrapper.on('mouseleave', function () {
                $overlay.css('opacity', 0);
                $previewHint.css('opacity', 0);
            });

            $uploadIcon.on('click', function (e) {
                if (images.length > 0) return; // já há ficheiro: não faz nada aqui, deixa o clique borbulhar até $overlay (abre a preview)
                e.stopPropagation();
                e.preventDefault();
                // Sem capture: no mobile abre o picker nativo normal (galeria/ficheiros,
                // tipicamente com "tirar foto" também disponível lá dentro, mas sem
                // forçar). Remove explicitamente, para o caso de ter ficado de um
                // clique anterior em Câmara no mesmo $fileInput partilhado.
                if (isMobile) $fileInput.removeAttr('capture');
                $fileInput.click();
            });

            $cameraIcon.on('click', function (e) {
                if (images.length > 0) return; // já há ficheiro: não faz nada aqui, deixa o clique borbulhar até $overlay (abre a preview)
                e.stopPropagation();
                e.preventDefault();
                if (isMobile) {
                    // No desktop usamos getUserMedia (iniciarWebcam) porque não há outra
                    // forma de chegar à câmara. No mobile, o próprio SO já sabe abrir a
                    // câmara nativa a partir de um <input type=file> — capture=environment
                    // é o que força ir direito a ela, em vez de mostrar o chooser genérico.
                    $fileInput.attr('capture', 'environment');
                    $fileInput.click();
                } else {
                    iniciarWebcam();
                }
            });

            // Drag & drop
            $wrapper.on('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#007bff',
                    'background': '#e7f1ff'
                });
            });

            $wrapper.on('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#e0e0e0',
                    'background': '#fafafa'
                });
            });

            $wrapper.on('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#e0e0e0',
                    'background': '#fafafa'
                });

                var file = e.originalEvent.dataTransfer.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function (ev) {
                        addImage(ev.target.result, file);
                    };
                    reader.readAsDataURL(file);
                }
            });

            $removeBtn.on('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                removeImage();
            });

            // Clicar na imagem/documento já carregado abre a pré-visualização. $overlay
            // cobre sempre a área toda (mesmo invisível fora do hover), por isso é onde
            // este clique tem de ser apanhado — um handler em $preview nunca dispararia,
            // já que $overlay está sempre por cima dela em z-index. Os ícones de câmara/
            // upload só chamam stopPropagation() quando o container está vazio (aí sim
            // fazem o que lhes compete); com ficheiro carregado, saem sem fazer nada e o
            // clique chega até aqui normalmente, mesmo que tenha caído em cima deles.
            $overlay.on('click', function (e) {
                if (images.length > 0) {
                    UploadCaptureCore.getLightbox().open(images[0]);
                }
            });

            $fileInput.on('change', function (e) {
                e.stopPropagation();
                var file = e.target.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function (ev) {
                        addImage(ev.target.result, file);
                    };
                    reader.readAsDataURL(file);
                }
                $(this).val('');
            });

            $captureBtn.on('click', function (e) {
                e.stopPropagation();
                var context = $canvas[0].getContext('2d');
                $canvas[0].width = $video[0].videoWidth || 640;
                $canvas[0].height = $video[0].videoHeight || 480;
                context.drawImage($video[0], 0, 0, $canvas[0].width, $canvas[0].height);

                var dataUrl = $canvas[0].toDataURL('image/jpeg', settings.quality);
                addImage(dataUrl);
                pararWebcam();
            });

            $closeVideoBtn.on('click', function (e) {
                e.stopPropagation();
                pararWebcam();
            });

            // Carregar imagem padrão
            if (settings.defaultImage) {
                addImage(settings.defaultImage);
            } else if ($original.val() && $original.is('input[type="text"]')) {
                addImage($original.val());
            }

            if (settings.onReady) settings.onReady();

            // Objeto com métodos públicos
            var instance = {
                destroy: function () {
                    $wrapper.remove();
                    $original.show();
                    images = [];
                    if ($videoContainer) $videoContainer.remove();
                    $fileInput = null;
                    $videoContainer = null;
                    $video = null;
                    $canvas = null;
                    // Remove a instância do data
                    $original.removeData('singleCapture');
                },
                reset: function () {
                    if (images.length > 0) {
                        removeImage();
                    } // função existente que limpa a imagem
                    $original.attr('type', 'file').val('');
                },
                // NOVO MÉTODO SETIMAGE
                setImage: function (src) {
                    // console.log('setImage chamado com src:', src);
                    if (src && src !== '') {
                        addImage(src, null);
                    }
                }
            };

            // Guarda a instância no data do elemento original
            $original.data('singleCapture', instance);
        });
    };

    $.singleCapture = function (selector, options) {
        return $(selector).singleCapture(options);
    };
})(jQuery);

/**
 * multipleCapture - Plugin para captura de MÚLTIPLAS imagens
 *
 * Depende de: nada entre os plugins deste arquivo (usa apenas APIs
 *             nativas do navegador: getUserMedia, FileReader). Pode ser
 *             usado isoladamente, independente de singleCapture.
 * 
 * @param {Object} options - Opções de configuração
 * @param {number} options.maxFiles - Número máximo de imagens (padrão: 10)
 * @param {Array} options.defaultImages - Array de URLs para imagens padrão
 * @param {number} options.height - Altura do container em pixels
 * @param {string} options.width - Largura do container (ex: '100%', '300px')
 * @param {number} options.quality - Qualidade da imagem (0.0 a 1.0)
 * @param {number} options.gridCols - Número de colunas no grid (padrão: 3)
 * @param {string} options.preferredCamera - 'front', 'back' ou 'default'
 * @param {boolean} options.showCameraSelection - Mostrar seletor de câmaras
 * @param {Object} options.buttonText - Textos personalizados para botões
 * @param {Object} options.messages - Mensagens personalizadas
 * @param {Function} options.onChange - Callback quando imagens mudam
 * @param {Function} options.onRemove - Callback quando imagem é removida
 * @param {Function} options.onReady - Callback quando plugin está pronto
 * 
 * @example
 * $('input[name="galeria[]"]').multipleCapture({
 *     maxFiles: 5,
 *     gridCols: 4,
 *     height: 250,
 *     defaultImages: ['/uploads/foto1.jpg', '/uploads/foto2.jpg']
 * });
 */
(function ($) {
    $.fn.multipleCapture = function (options) {
        if (typeof options === 'string') {
            var args = Array.prototype.slice.call(arguments, 1);
            this.each(function () {
                var instance = $(this).data('multipleCapture');
                if (instance && typeof instance[options] === 'function') {
                    instance[options].apply(instance, args);
                }
            });
            return this;
        }
        var settings = $.extend({
            maxFiles: 10,
            defaultImages: [],
            height: 200,
            width: '100%',
            quality: 0.8,
            gridCols: 3,
            accept: null, // sem restrição por omissão — validação de tipo é responsabilidade do servidor, não do cliente
            buttonText: {
                camera: 'Câmara',
                upload: 'Upload'
            },
            messages: {
                default: 'Clique ou arraste para adicionar ficheiros',
                maxFiles: 'Máximo de {max} ficheiros atingido'
            },
            onChange: null,
            onRemove: null,
            onReady: null
        }, options);

        return this.each(function () {
            var $original = $(this);
            var inputName = $original.attr('name') || 'fotos_' + Date.now();
            var images = [];

            // Tamanhos responsivos (mais pequenos)
            var baseSize = Math.min(settings.height, 200);
            var iconSize = Math.min(32, Math.max(20, Math.floor(baseSize * 0.2))); // 20% da altura
            var fontSize = Math.min(13, Math.max(11, Math.floor(baseSize * 0.07))); // 7% da altura
            var padding = Math.min(10, Math.max(5, Math.floor(baseSize * 0.05))); // 5% da altura

            var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

            // Input file — sem atributo accept quando settings.accept não é definido,
            // logo sem filtro nenhum: o selector do SO mostra TODOS os tipos de ficheiro.
            // 'accept', quando definido, é só um filtro de UI do selector — a validação
            // de tipo/tamanho a sério continua a ser feita no servidor, nunca no cliente.
            // 'capture' NÃO é definido aqui — é alternado por clique (Câmara põe,
            // Upload remove), porque os dois botões partilham este mesmo $fileInput.
            var $fileInput = $('<input type="file" multiple>').css('display', 'none');
            if (settings.accept) $fileInput.attr('accept', settings.accept);

            // Container principal
            var $wrapper = $('<div class="multiple-capture-wrapper"></div>').css({
                'display': 'flex',
                'width': settings.width,
                'height': settings.height + 'px',
                'border': '2px dashed #e0e0e0',
                'border-radius': '8px',
                'background': '#fafafa',
                'overflow': 'hidden',
                'box-sizing': 'border-box'
            });

            $original.data('multiple-capture-wrapper', $wrapper);

            // ===== BARRA ESQUERDA (15%) =====
            var $leftBar = $('<div class="multiple-capture-left"></div>').css({
                'width': '15%',
                'min-width': '80px',
                'max-width': '120px',
                'display': 'flex',
                'flex-direction': 'column',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '15px',
                'padding': '10px',
                'border-right': '1px solid #e0e0e0',
                'height': '100%',
                'box-sizing': 'border-box'
            });

            // Ícone upload (com borda azul tracejada)
            var $uploadIcon = $('<div class="multiple-upload-icon"></div>').css({
                'display': 'flex',
                'flex-direction': 'column',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '5px',
                'cursor': 'pointer',
                'padding': padding + 'px',
                'border-radius': '8px',
                'border': '2px dashed #007bff',
                'background': '#e7f1ff',
                'width': '100%',
                'transition': 'all 0.3s ease',
                'box-sizing': 'border-box'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="#007bff" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <div style="font-size:${fontSize}px; color:#007bff;">${settings.buttonText.upload}</div>
            `);

            // Ícone câmara (com borda azul tracejada)
            var $cameraIcon = $('<div class="multiple-camera-icon"></div>').css({
                'display': 'flex',
                'flex-direction': 'column',
                'align-items': 'center',
                'justify-content': 'center',
                'gap': '5px',
                'cursor': 'pointer',
                'padding': padding + 'px',
                'border-radius': '8px',
                'border': '2px dashed #007bff',
                'background': '#e7f1ff',
                'width': '100%',
                'transition': 'all 0.3s ease',
                'box-sizing': 'border-box'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="#007bff" stroke-width="2">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
                <div style="font-size:${fontSize}px; color:#007bff;">${settings.buttonText.camera}</div>
            `);

            // Hover effects
            $uploadIcon.hover(
                function () { $(this).css({ 'border': '2px solid #007bff', 'background': '#d4e4ff', 'transform': 'translateY(-2px)' }); },
                function () { $(this).css({ 'border': '2px dashed #007bff', 'background': '#e7f1ff', 'transform': 'translateY(0)' }); }
            );

            $cameraIcon.hover(
                function () { $(this).css({ 'border': '2px solid #007bff', 'background': '#d4e4ff', 'transform': 'translateY(-2px)' }); },
                function () { $(this).css({ 'border': '2px dashed #007bff', 'background': '#e7f1ff', 'transform': 'translateY(0)' }); }
            );

            $leftBar.append($uploadIcon, $cameraIcon);

            // ===== GRID DIREITO (85%) =====
            var $rightGrid = $('<div class="multiple-capture-grid"></div>').css({
                'width': '85%',
                'position': 'relative',         // âncora para o texto vazio se centrar via position:absolute
                'display': 'grid',
                'grid-template-columns': 'repeat(' + settings.gridCols + ', 1fr)',
                'grid-auto-rows': 'auto',       // cada linha ajusta-se à altura natural do próprio conteúdo (o aspect-ratio:1 dos itens já garante que essa altura natural bate certo com a largura da coluna); 1fr forçava uma fracção igual do container que raramente coincidia com isso, e a inconsistência comia visualmente o gap vertical
                'align-content': 'start',        // impede que o CONJUNTO de linhas estique para preencher o container quando sobra altura
                'gap': '8px',
                'padding': '10px',
                'overflow-y': 'scroll', // reserva sempre o espaço da barra, mesmo sem precisar de scroll — evita que a largura disponível (e por isso a altura de cada item quadrado, via aspect-ratio) mude consoante a barra aparece ou não
                'height': '100%',
                'background': '#e9ecef', // cor de fundo própria para a área do gap — antes era transparente e mostrava o #fafafa do wrapper, quase indistinguível do #fff dos itens; com imagens de baixo contraste, a separação ficava a depender só de uma borda de 1px, fácil de perder à vista
                'box-sizing': 'border-box'
            });

            // Texto vazio — centrado nos dois eixos (mesma técnica de singleCapture),
            // e não a "flutuar" no topo do grid vazio.
            var $emptyText = $('<div class="multiple-capture-empty"></div>').css({
                'position': 'absolute',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'width': '100%',
                'text-align': 'center',
                'color': '#999',
                'padding': '20px',
                'box-sizing': 'border-box',
                'font-size': fontSize + 'px'
            }).html(`
                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="#999">
                    <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                    <circle cx="12" cy="12" r="4"/>
                </svg>
                <div style="margin-top:10px;">${settings.messages.default}</div>
            `);

            $rightGrid.append($emptyText);

            // Montagem
            // Montagem - elementos visíveis dentro do wrapper
            $wrapper.append($leftBar, $rightGrid);

            // Elementos invisíveis fora do wrapper (depois dele)
            $wrapper.after($fileInput);

            $original.after($wrapper);
            $original.hide();
            // ===== NOVO: container para os inputs =====
            var $originalContainer = $('<div class="original-file-container" style="display:none;"></div>');
            $original.after($originalContainer);

            // Webcam container
            var $videoContainer = $('<div class="webcam-container"></div>').css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'z-index': 10000,
                'background': '#000',
                'border-radius': '8px',
                'display': 'none',
                'width': '90%',
                'max-width': '640px'
            });

            var $video = $('<video autoplay></video>').css({
                'width': '100%',
                'height': 'auto',
                'display': 'block'
            });

            var $canvas = $('<canvas></canvas>').css('display', 'none');

            var $videoControls = $('<div></div>').css({
                'padding': '10px',
                'background': '#333',
                'text-align': 'center'
            });

            var $captureBtn = $('<button>Capturar</button>').css({
                'padding': '8px 20px',
                'background': '#28a745',
                'color': '#fff',
                'border': 'none',
                'border-radius': '3px',
                'cursor': 'pointer',
                'margin-right': '10px'
            });

            var $closeVideoBtn = $('<button>Fechar</button>').css({
                'padding': '8px 20px',
                'background': '#dc3545',
                'color': '#fff',
                'border': 'none',
                'border-radius': '3px',
                'cursor': 'pointer'
            });

            $videoControls.append($captureBtn, $closeVideoBtn);
            $videoContainer.append($video, $canvas, $videoControls);
            $('body').append($videoContainer);

            // Lightbox de pré-visualização — partilhado com singleCapture via
            // UploadCaptureCore (ver comentário no topo do ficheiro). Não é construído
            // aqui: é um singleton de página, criado uma única vez na primeira chamada.
            var previewLightbox = UploadCaptureCore.getLightbox();

            var stream = null;

            function addImage(src, file) {
                if (images.length >= settings.maxFiles) {
                    alert(settings.messages.maxFiles.replace('{max}', settings.maxFiles));
                    return false;
                }

                var meta = UploadCaptureCore.detectFileMeta(src, file);
                images.push({ src: src, file: file, isBase64: meta.isBase64, mime: meta.mime, name: meta.name, isImage: meta.isImage });

                // Atualizar o input original com os valores
                updateOriginalInput();

                renderGrid();

                if (settings.onChange) settings.onChange(images);
                return true;
            }

            function updateOriginalInput() {
                $originalContainer.empty();

                var baseName = inputName.replace(/\[\]$/, ''); // remove [] se houver

                images.forEach(function (img) {
                    if (img.isBase64) {
                        // O mime já vai embutido no próprio data URI (data:<mime>;base64,...),
                        // por isso não precisa de campo próprio. O nome original (quando existe —
                        // capturas de câmara não têm) vai à parte, indexado à mesma posição,
                        // para o servidor poder gerar um nome/extensão correcto em vez de assumir .png.
                        $('<input>').attr({
                            'type': 'hidden',
                            'name': baseName + '_base64[]',
                            'value': img.src
                        }).appendTo($originalContainer);

                        $('<input>').attr({
                            'type': 'hidden',
                            'name': baseName + '_base64_names[]',
                            'value': img.name || ''
                        }).appendTo($originalContainer);
                    } else {
                        // Paths como array
                        $('<input>').attr({
                            'type': 'hidden',
                            'name': baseName + '_paths[]',
                            'value': img.src
                        }).appendTo($originalContainer);
                    }
                });
            }

            function removeImage(index) {
                images.splice(index, 1);

                // Atualizar input original
                updateOriginalInput();

                renderGrid();

                if (settings.onRemove) settings.onRemove(index);
                if (settings.onChange) settings.onChange(images);
            }

            // No renderGrid
            function renderGrid() {
                $rightGrid.empty();

                if (images.length === 0) {
                    $rightGrid.append($emptyText);
                    return;
                }

                images.forEach(function (img, index) {
                    // Render grid item (código existente)
                    var $item = $('<div class="grid-item"></div>').css({
                        'position': 'relative',
                        'aspect-ratio': '1',            // substitui padding-top: 100%
                        'border': '1px solid #ddd',
                        'border-radius': '4px',
                        'overflow': 'hidden',
                        'background': '#fff'
                    });

                    var $imgDiv;

                    if (img.isImage) {
                        $imgDiv = $('<div></div>').css({
                            'position': 'absolute',
                            'top': 0,
                            'left': 0,
                            'width': '100%',
                            'height': '100%',
                            'background-image': 'url(' + img.src + ')',
                            'background-size': 'cover',
                            'background-position': 'center'
                        });
                    } else {
                        // Documento (pdf/doc/docx/etc.): sem preview possível, mostra
                        // ícone + extensão — mesmo helper usado por singleCapture, para
                        // ficar visualmente idêntico nos dois.
                        var ext = (img.name || '').split('.').pop().toUpperCase();
                        if (!ext || ext === img.name) ext = 'FICHEIRO';

                        $imgDiv = $('<div></div>').css({
                            'position': 'absolute',
                            'top': 0,
                            'left': 0,
                            'width': '100%',
                            'height': '100%',
                            'display': 'flex',
                            'flex-direction': 'column',
                            'align-items': 'center',
                            'justify-content': 'center',
                            'gap': '4px',
                            'padding': '6px',
                            'box-sizing': 'border-box',
                            'background': '#f1f3f5',
                            'text-align': 'center'
                        }).html(UploadCaptureCore.docIconHtml(26, ext));
                    }

                    // Clicar no item abre a pré-visualização (imagem grande, PDF em
                    // iframe, vídeo em player, ou aviso + download para tipos sem preview
                    // possível). Usa o mesmo lightbox partilhado de singleCapture.
                    $imgDiv.css('cursor', 'pointer').on('click', function (e) {
                        e.stopPropagation();
                        previewLightbox.open(img);
                    });

                    var $remove = $('<div class="grid-remove"></div>').css({
                        'position': 'absolute',
                        'top': '3px',
                        'right': '3px',
                        'width': '20px',
                        'height': '20px',
                        'border-radius': '3px',
                        'background': '#dc3545',
                        'color': '#fff',
                        'display': 'flex',
                        'align-items': 'center',
                        'justify-content': 'center',
                        'cursor': 'pointer',
                        'font-size': '12px',
                        'z-index': 2
                    }).html('✕').on('click', function (e) {
                        e.stopPropagation();
                        removeImage(index);
                    });

                    $item.append($imgDiv, $remove);
                    $rightGrid.append($item);
                });
            }

            function iniciarWebcam() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    alert('A câmara só funciona em ligações seguras (HTTPS). Por favor use upload de ficheiro.');
                    return;
                }
                navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function (s) {
                        stream = s;
                        $video[0].srcObject = stream;
                        $videoContainer.show();
                    })
                    .catch(function (err) { alert('Erro: ' + err.message); });
            }

            function pararWebcam() {
                if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
                $videoContainer.hide();
            }

            // Eventos
            $uploadIcon.on('click', function (e) {
                e.stopPropagation();
                // Sem capture: no mobile abre o picker nativo normal (galeria/ficheiros).
                // Remove explicitamente, para o caso de ter ficado de um clique anterior
                // em Câmara no mesmo $fileInput partilhado.
                if (isMobile) $fileInput.removeAttr('capture');
                $fileInput.click();
            });

            $cameraIcon.on('click', function (e) {
                e.stopPropagation();
                if (isMobile) {
                    // No desktop usamos getUserMedia (iniciarWebcam). No mobile, o SO
                    // já sabe abrir a câmara nativa a partir de um <input type=file> —
                    // capture=environment força ir direito a ela, em vez do chooser genérico.
                    $fileInput.attr('capture', 'environment');
                    $fileInput.click();
                } else {
                    iniciarWebcam();
                }
            });

            // Drag & drop
            $wrapper.on('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#007bff',
                    'background': '#e7f1ff'
                });
            });

            $wrapper.on('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#e0e0e0',
                    'background': '#fafafa'
                });
            });

            $wrapper.on('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $wrapper.css({
                    'border-color': '#e0e0e0',
                    'background': '#fafafa'
                });

                var files = e.originalEvent.dataTransfer.files;
                for (var i = 0; i < files.length; i++) {
                    if (images.length >= settings.maxFiles) break;

                    (function (file) {
                        var reader = new FileReader();
                        reader.onload = function (ev) {
                            addImage(ev.target.result, file);
                        };
                        reader.readAsDataURL(file);
                    })(files[i]);
                }
            });

            // Clique no grid vazio
            $rightGrid.on('click', function (e) {
                if ($(e.target).is('.multiple-capture-grid') ||
                    $(e.target).is('.multiple-capture-empty') ||
                    $(e.target).closest('.grid-item').length === 0) {
                    $fileInput.click();
                }
            });

            // No $fileInput.on('change') - adicionar verificação
            $fileInput.on('change', function (e) {
                var files = e.target.files;

                for (var i = 0; i < files.length; i++) {
                    if (images.length >= settings.maxFiles) break;

                    (function (file) {
                        var reader = new FileReader();
                        reader.onload = function (ev) {
                            addImage(ev.target.result, file);
                        };
                        reader.readAsDataURL(file);
                    })(files[i]);
                }

                $(this).val('');
            });

            $captureBtn.on('click', function (e) {
                e.stopPropagation();
                var context = $canvas[0].getContext('2d');
                $canvas[0].width = $video[0].videoWidth || 640;
                $canvas[0].height = $video[0].videoHeight || 480;
                context.drawImage($video[0], 0, 0);

                var dataUrl = $canvas[0].toDataURL('image/jpeg', settings.quality);
                addImage(dataUrl);
            });

            $closeVideoBtn.on('click', function (e) {
                e.stopPropagation();
                pararWebcam();
            });

            // Carregar imagens padrão
            if (settings.defaultImages && settings.defaultImages.length) {
                settings.defaultImages.forEach(function (src) { addImage(src); });
            }

            if (settings.onReady) settings.onReady();

            // Objeto com métodos públicos
            var instance = {
                destroy: function () {
                    $originalContainer.remove();
                    $wrapper.remove();
                    $original.show();
                    images = [];
                    if ($videoContainer) $videoContainer.remove();
                    // Nota: o lightbox de preview NÃO é removido aqui — é partilhado
                    // (singleton de página, via UploadCaptureCore) com outros campos
                    // singleCapture/multipleCapture que possam ainda estar na página.
                    $fileInput = null;
                    $videoContainer = null;
                    $video = null;
                    $canvas = null;
                    $original.removeData('multipleCapture');
                },
                reset: function () {
                    images = [];                 // limpa o array de imagens
                    renderGrid();                 // atualiza o grid (mostra texto vazio)
                    $originalContainer.empty();   // remove todos os inputs hidden
                    $original.val('');            // limpa o valor do input original (opcional)
                    $fileInput.val('');           // limpa o input file interno (opcional)
                },
                // NOVO MÉTODO PARA ADICIONAR UMA IMAGEM
                addImage: function (src) {
                    if (src && src !== '') {
                        addImage(src, null);
                    }
                },
                // NOVO MÉTODO PARA ADICIONAR MÚLTIPLAS IMAGENS
                setImages: function (srcArray) {
                    if (Array.isArray(srcArray)) {
                        srcArray.forEach(function (src) {
                            if (src && src !== '') {
                                addImage(src, null);
                            }
                        });
                    }
                },
                // NOVO MÉTODO PARA LIMPAR TODAS AS IMAGENS
                clear: function () {
                    images = [];
                    renderGrid();
                    $originalContainer.empty();
                    $original.val('');
                    $fileInput.val('');
                }
            };

            // Guarda a instância no data do elemento original
            $original.data('multipleCapture', instance);
        });
    };

    $.multipleCapture = function (selector, options) {
        return $(selector).multipleCapture(options);
    };
})(jQuery);

/**
 * uploadCapture - Plugin unificador para upload (câmara e/ou ficheiro)
 * Alterna automaticamente entre singleCapture e multipleCapture
 *
 * Cada instância expõe DOIS botões independentes: "Câmara" (captura via
 * webcam, sempre produz imagem — limitação inerente da captura por câmara)
 * e "Upload" (input de ficheiro normal, sem restrição de tipo por omissão
 * — ver options.accept). Os dois botões são independentes entre si —
 * usar só um, só o outro, ou alternar entre ambos no mesmo campo já
 * funciona sem configuração adicional, até ao limite de options.maxFiles.
 *
 * singleCapture e multipleCapture têm sempre as mesmas funcionalidades
 * (accept livre, preview com download, ícone genérico para tipos sem
 * preview possível) — a única diferença entre eles é suportar 1 ficheiro
 * ou N. Nenhum dos dois restringe tipo de ficheiro por omissão: quem
 * quiser restringir um campo específico (ex: um avatar só a imagens)
 * passa options.accept explicitamente; validação a sério continua a ser
 * sempre do servidor, nunca do cliente.
 *
 * Depende de: singleCapture e/ou multipleCapture (definidos acima, neste
 *             mesmo módulo) — mas com checagem defensiva
 *             ("if ($.fn.singleCapture)" / "if ($.fn.multipleCapture)"):
 *             se usar options.multiple=false, só precisa de singleCapture
 *             carregado; se multiple=true, só precisa de multipleCapture.
 *             Se o plugin necessário não estiver presente, uploadCapture
 *             não quebra — apenas loga um console.error. É o único
 *             plugin deste arquivo que resolve suas dependências de
 *             forma totalmente segura.
 * 
 * @param {Object} options - Opções de configuração
 * @param {boolean} options.multiple - true para múltiplos ficheiros, false para único
 * @param {number} options.maxFiles - Número máximo de itens (para multiple)
 * @param {number} options.gridCols - Número de colunas no grid (para multiple)
 * @param {number} options.height - Altura do container em pixels
 * @param {string} options.width - Largura do container (ex: '100%', '300px')
 * @param {string} options.accept - Atributo accept do input de ficheiro. Sem restrição por omissão em single e multiple — só filtra se definido explicitamente. Ex: 'image/*,application/pdf,.doc,.docx'
 * @param {string} options.defaultImage - URL da imagem padrão (para single)
 * @param {Array} options.defaultImages - Array de URLs para imagens padrão (para multiple)
 * @param {string} options.preferredCamera - 'front', 'back' ou 'default'
 * @param {boolean} options.showCameraSelection - Mostrar seletor de câmaras
 * @param {Object} options.buttonText - Textos personalizados para botões
 * @param {Object} options.messages - Mensagens personalizadas
 * @param {Function} options.onChange - Callback quando item(ns) mudam
 * @param {Function} options.onRemove - Callback quando item é removido
 * @param {Function} options.onReady - Callback quando plugin está pronto
 * 
 * @example
 * // Para imagem única (avatar, por exemplo). Sem accept, aceitaria qualquer
 * // tipo de ficheiro — para um avatar normalmente faz sentido restringir
 * // explicitamente a imagens, como abaixo.
 * $('input[name="foto"]').uploadCapture({
 *     multiple: false,
 *     height: 200,
 *     accept: 'image/*',
 *     defaultImage: '/images/avatar.jpg'
 * });
 * 
 * @example
 * // Para múltiplos ficheiros, sem restrição de tipo (comportamento por
 * // omissão — não é preciso passar accept para isto)
 * $('input[name="files[]"]').uploadCapture({
 *     multiple: true,
 *     maxFiles: 10,
 *     gridCols: 4,
 *     height: 250
 * });
 *
 * @example
 * Para destruir
 * $('input[name="profile"]').singleCapture('destroy');
 * $('input[name="profiles[]"]').multipleCapture('destroy');
 * 
 */
(function ($) {
    $.fn.uploadCapture = function (options) {

        // Se for string, é um comando (ex: 'reset', 'destroy')
        if (typeof options === 'string') {
            var args = Array.prototype.slice.call(arguments, 1);
            this.each(function () {
                var $this = $(this);
                // Tenta obter a instância de qualquer um dos plugins
                var instance = $this.data('singleCapture') || $this.data('multipleCapture');
                if (instance && typeof instance[options] === 'function') {
                    instance[options].apply(instance, args);
                }
            });
            return this;
        }

        var settings = $.extend({
            multiple: false
        }, options);

        return this.each(function () {
            var $this = $(this);

            if (settings.multiple) {
                // Usar multipleCapture
                if ($.fn.multipleCapture) {
                    $this.multipleCapture(options);
                } else {
                    console.error('multipleCapture plugin não carregado!');
                }
            } else {
                // Usar singleCapture
                if ($.fn.singleCapture) {
                    $this.singleCapture(options);
                } else {
                    console.error('singleCapture plugin não carregado!');
                }
            }
        });
    };

    $.uploadCapture = function (selector, options) {
        return $(selector).uploadCapture(options);
    };
})(jQuery);

/**
 * uploadFile - Gestor de anexos autónomo, em modal próprio
 *
 * Diferente de single/multipleCapture: NÃO fica embutido num formulário à
 * espera de submit. Actua directamente contra o servidor — escolher um
 * ficheiro envia-o de imediato (settings.uploadUrl), marcar linhas e
 * clicar "Remove" apaga-as de imediato (settings.deleteUrl). Pensado para
 * o caso "gerir anexos já existentes de um registo", não para "juntar
 * ficheiros a um formulário que ainda vai ser submetido" — para esse
 * caso, usa uploadCapture.
 *
 * Reaproveita UploadCaptureCore (definido no topo deste ficheiro) para
 * detecção de tipo e para a pré-visualização — "[view]" abre exactamente
 * o mesmo lightbox que singleCapture/multipleCapture já usam. Não
 * duplica essa lógica.
 *
 * single vs. multiple é só settings.multiple + settings.maxFiles — uma
 * implementação só, não duas em paralelo (foi o que gerou trabalho a
 * dobrar da primeira vez que fizemos isto).
 *
 * UMA instância serve QUALQUER número de registos — não é preciso
 * inicializar um por linha. settings.referenceId é só o valor inicial
 * (útil quando há mesmo só um registo fixo na página); o id real de
 * cada abertura vem por open(id), chamado a partir de fora, tal como já
 * fazes no teu .btnUploadFile com $(this).data('id'). É isto que permite
 * uma tabela com N linhas, cada uma com o seu botão "Anexos", partilhar
 * a MESMA instância/modal sem reinicializar nada por linha.
 *
 * @param {Object} options
 * @param {boolean} options.multiple - true permite vários anexos, false só 1. (default: true)
 * @param {number} options.maxFiles - Máximo de anexos quando multiple:true. null = sem limite. Ignorado quando multiple:false (fica sempre 1).
 * @param {number|string} options.referenceId - Valor INICIAL, opcional. Normalmente o id real vem depois, por open(id) — ver exemplo 2.
 * @param {string} options.listUrl - URL GET. Recebe ?id=X. Espera {success:true, files:[{id, name, url, mime, size, uploaded_at, uploaded_by}, ...]}. uploaded_at/uploaded_by são opcionais — a linha simplesmente não mostra essa parte se vierem vazios.
 * @param {string} options.uploadUrl - URL POST (multipart). Envia 'file' + 'id'. Espera {success:true, file:{id,name,url,mime,size,...}} ou {success:false, message}.
 * @param {string} options.deleteUrl - URL POST. Envia 'ids[]' (array, mesmo para 1 só) + 'id'. Espera {success:true} ou {success:false, message}.
 * @param {string} options.downloadAllUrl - Opcional. URL GET que devolve um .zip pronto a descarregar. Sem isto, "Download All" volta ao comportamento anterior (um download por ficheiro, em sequência).
 * @param {string} options.category - Opcional. Vai em todos os pedidos (list/upload/delete), mas só filtra de facto quando o backend souber usar isto — pensado para o caso de vários campos de anexo (anexo1/anexo2/anexo3) no mesmo registo.
 * @param {string} options.accept - Atributo accept do input. Sem restrição por omissão (ver uploadCapture, mesma filosofia).
 * @param {string} options.title - Título do modal. (default: 'Anexos')
 * @param {boolean} options.confirmRemove - Pede confirmação antes de remover. (default: true)
 * @param {Function} options.onChange - Callback quando a lista muda (upload ou remoção concluídos).
 * @param {Function} options.onReady - Callback quando o plugin está pronto.
 *
 * @example
 * // Caso simples: um registo fixo, sempre o mesmo, numa página de edição
 * $('input[name="anexos[]"]').uploadFile({
 *     multiple: true,
 *     referenceId: 42,
 *     listUrl: '/entradas/anexos',
 *     uploadUrl: '/entradas/anexos/upload',
 *     deleteUrl: '/entradas/anexos/remover',
 *     title: 'Anexos da Entrada'
 * });
 *
 * @example
 * // Tabela com várias linhas: inicializa UMA vez (sem referenceId nenhum),
 * // e cada botão da tabela passa o seu próprio id ao abrir.
 * $('#anexoHidden').uploadFile({
 *     multiple: true,
 *     listUrl: '/entradas/anexos',
 *     uploadUrl: '/entradas/anexos/upload',
 *     deleteUrl: '/entradas/anexos/remover'
 * });
 * $(document).on('click', '.btnAnexos', function () {
 *     $('#anexoHidden').uploadFile('open', $(this).data('id'));
 * });
 */
(function ($) {
    $.fn.uploadFile = function (options) {
        if (typeof options === 'string') {
            var args = Array.prototype.slice.call(arguments, 1);
            var results = [];
            this.each(function () {
                var instance = $(this).data('uploadFile');
                if (instance && typeof instance[options] === 'function') {
                    results.push(instance[options].apply(instance, args));
                }
            });
            return results.length === 1 ? results[0] : results;
        }

        var settings = $.extend({
            multiple: true,
            maxFiles: null,
            referenceId: null,
            category: null,          // opcional — vai em todos os pedidos (list/upload/delete), mas só tem efeito quando o backend souber filtrar por isto
            listUrl: null,
            uploadUrl: null,
            deleteUrl: null,
            downloadAllUrl: null,     // opcional — se definido, Download All pede um zip a este URL em vez de descarregar ficheiro a ficheiro
            accept: null,
            title: 'Anexos',
            confirmRemove: true,
            onChange: null,
            onReady: null
        }, options);

        var maxFiles = settings.multiple ? (settings.maxFiles || Infinity) : 1;

        return this.each(function () {
            var $original = $(this);

            // Protecção: se este elemento já tem uma instância (ex: .uploadFile({...})
            // foi chamado outra vez, por engano, dentro de um handler de clique em vez
            // de correr uma vez só), não monta tudo de novo — isso acumulava um modal
            // escondido no <body> a cada clique. Em vez disso, só abre a instância que
            // já existe, actualizando o referenceId se um novo tiver vindo em settings.
            var existing = $original.data('uploadFile');
            if (existing) {
                if (settings.referenceId !== null && settings.referenceId !== undefined) {
                    existing.setReferenceId(settings.referenceId);
                }
                existing.open();
                return;
            }

            var referenceId = settings.referenceId;
            var files = []; // cache local do que veio do listUrl

            if (!settings.listUrl || !settings.uploadUrl || !settings.deleteUrl) {
                console.error('uploadFile: listUrl, uploadUrl e deleteUrl são obrigatórios.');
                return;
            }

            $original.hide();

            // ===== ÍCONES POR TIPO — mesma linha fina do resto do ficheiro, cor a variar por tipo =====
            function typeIconHtml(mime, name) {
                var ext = (name || '').split('.').pop().toLowerCase();
                var isImage = (mime && mime.indexOf('image/') === 0) || ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) > -1;
                var isPdf = mime === 'application/pdf' || ext === 'pdf';
                var isWord = ['doc', 'docx'].indexOf(ext) > -1 || (mime || '').indexOf('wordprocessingml') > -1 || mime === 'application/msword';

                var color = '#6c757d'; // genérico
                if (isImage) color = '#20c997';
                else if (isPdf) color = '#dc3545';
                else if (isWord) color = '#0d6efd';

                return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="1.8">' +
                    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
                    '<polyline points="14 2 14 8 20 8"/>' +
                    '</svg>';
            }

            // ===== TRIGGER (substitui o input original) =====
            var $trigger = $('<button type="button" class="upload-file-trigger"></button>').css({
                'display': 'inline-flex', 'align-items': 'center', 'gap': '6px',
                'padding': '6px 12px', 'border': '1px solid #ccc', 'border-radius': '6px',
                'background': '#fff', 'color': '#333', 'font-size': '13px', 'cursor': 'pointer'
            }).html(
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2">' +
                '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>' +
                '</svg> ' + settings.title + ' <span class="upload-file-count">(0)</span>'
            );
            $trigger.hover(
                function () { $(this).css('background', '#f5f5f5'); },
                function () { $(this).css('background', '#fff'); }
            );
            $original.after($trigger);

            var $countEl = $trigger.find('.upload-file-count');

            // ===== MODAL =====
            var $backdrop = $('<div class="upload-file-backdrop"></div>').css({
                'position': 'fixed', 'top': 0, 'left': 0, 'right': 0, 'bottom': 0,
                'background': 'rgba(0,0,0,0.6)', 'z-index': 10002, 'display': 'none',
                'align-items': 'flex-start', 'justify-content': 'center', // no topo, não centrado verticalmente
                'padding': '40px 20px 20px', 'box-sizing': 'border-box', 'overflow-y': 'auto'
            });

            var $modal = $('<div class="upload-file-modal"></div>').css({
                'position': 'relative', 'background': '#fff', 'border-radius': '8px',
                'width': '640px', 'max-width': '95vw', 'max-height': '85vh',
                'display': 'flex', 'flex-direction': 'column', 'overflow': 'hidden',
                'box-shadow': '0 4px 30px rgba(0,0,0,0.4)', 'box-sizing': 'border-box'
            });

            var $header = $('<div class="upload-file-header"></div>').css({
                'display': 'flex', 'align-items': 'center', 'justify-content': 'space-between',
                'padding': '14px 18px', 'border-bottom': '1px solid #e0e0e0', 'flex-shrink': 0
            });
            var $title = $('<div></div>').css({ 'font-size': '16px', 'color': '#333' }).text(settings.title);
            var $closeBtn = $('<div></div>').css({
                'cursor': 'pointer', 'color': '#666', 'font-size': '18px', 'padding': '2px 6px'
            }).html('✕').hover(
                function () { $(this).css('color', '#333'); },
                function () { $(this).css('color', '#666'); }
            );
            $header.append($title, $closeBtn);

            var $body = $('<div class="upload-file-body"></div>').css({
                'flex': '1', 'overflow-y': 'auto', 'padding': '8px 0'
            });

            var $emptyRow = $('<div></div>').css({
                'text-align': 'center', 'color': '#999', 'padding': '30px 20px', 'font-size': '13px'
            }).text('Nenhum anexo.');

            var $footer = $('<div class="upload-file-footer"></div>').css({
                'display': 'flex', 'align-items': 'center', 'gap': '10px',
                'padding': '12px 18px', 'border-top': '1px solid #e0e0e0', 'flex-shrink': 0
            });

            var $chooseBtn = $('<button type="button">Choose file</button>').css({
                'padding': '7px 16px', 'border': 'none', 'border-radius': '5px',
                'background': '#ffc107', 'color': '#fff', 'font-size': '13px',
                'cursor': 'pointer', 'font-weight': '500'
            });
            var $downloadAllBtn = $('<button type="button">Download All</button>').css({
                'padding': '7px 16px', 'border': '1px solid #ccc', 'border-radius': '5px',
                'background': '#fff', 'color': '#333', 'font-size': '13px', 'cursor': 'pointer'
            });
            var $removeBtn = $('<button type="button">Remove</button>').css({
                'padding': '7px 16px', 'border': '1px solid #dc3545', 'border-radius': '5px',
                'background': '#fff', 'color': '#dc3545', 'font-size': '13px',
                'cursor': 'not-allowed', 'opacity': '0.5', 'margin-left': 'auto'
            }).prop('disabled', true);

            $footer.append($chooseBtn, $downloadAllBtn, $removeBtn);

            var $fileInput = $('<input type="file">').css('display', 'none');
            if (settings.accept) $fileInput.attr('accept', settings.accept);
            if (settings.multiple && maxFiles > 1) $fileInput.attr('multiple', 'multiple');

            $modal.append($header, $body, $footer, $fileInput);
            $backdrop.append($modal);
            $('body').append($backdrop);

            // ===== RENDER =====
            function updateRemoveState() {
                var anyChecked = $body.find('.upload-file-checkbox:checked').length > 0;
                $removeBtn.prop('disabled', !anyChecked).css({
                    'cursor': anyChecked ? 'pointer' : 'not-allowed',
                    'opacity': anyChecked ? '1' : '0.5'
                });
            }

            function formatDate(iso) {
                if (!iso) return '';
                try {
                    var d = new Date(iso);
                    if (isNaN(d.getTime())) return iso; // já vem formatada, mostra tal como veio
                    var p = function (n) { return String(n).padStart(2, '0'); };
                    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
                } catch (e) { return iso; }
            }

            function renderList() {
                $body.empty();
                $countEl.text('(' + files.length + ')');

                if (files.length === 0) {
                    $body.append($emptyRow);
                    updateRemoveState();
                    return;
                }

                files.forEach(function (file) {
                    var meta = UploadCaptureCore.detectFileMeta(file.url, null);
                    // mime do servidor tem prioridade sobre o deduzido da URL, quando vier
                    var item = {
                        src: file.url, name: file.name || '', mime: file.mime || meta.mime,
                        isImage: file.mime ? file.mime.indexOf('image/') === 0 : meta.isImage
                    };

                    var $row = $('<div class="upload-file-row"></div>').css({
                        'display': 'flex', 'align-items': 'center', 'gap': '10px',
                        'padding': '8px 18px', 'font-size': '13px', 'color': '#333',
                        'border-bottom': '1px solid #f0f0f0'
                    }).attr('data-id', file.id);

                    var $checkbox = $('<input type="checkbox" class="upload-file-checkbox">').css({ 'flex-shrink': 0, 'cursor': 'pointer' });
                    var $icon = $('<span></span>').css({ 'flex-shrink': 0, 'display': 'flex' }).html(typeIconHtml(item.mime, item.name));
                    var $name = $('<span></span>').css({
                        'flex': '1', 'min-width': '0', 'overflow': 'hidden', 'text-overflow': 'ellipsis', 'white-space': 'nowrap'
                    }).text(item.name);

                    var $downloadLink = $('<a>download</a>').css({
                        'color': '#007bff', 'cursor': 'pointer', 'flex-shrink': 0, 'text-decoration': 'none'
                    }).attr({ href: item.src, download: item.name || 'ficheiro' });

                    var $viewLink = $('<a>view</a>').css({
                        'color': '#007bff', 'cursor': 'pointer', 'flex-shrink': 0, 'text-decoration': 'none'
                    }).on('click', function (e) {
                        e.preventDefault();
                        UploadCaptureCore.getLightbox().open(item); // reaproveita o lightbox já existente
                    });

                    var $meta = $('<span></span>').css({
                        'flex-shrink': 0, 'color': '#999', 'font-size': '11px', 'white-space': 'nowrap'
                    });
                    var metaParts = [];
                    if (file.uploaded_at) metaParts.push(formatDate(file.uploaded_at));
                    if (file.uploaded_by) metaParts.push('Uploaded by: ' + file.uploaded_by);
                    $meta.text(metaParts.join(' — ')); // fica vazio, sem quebrar nada, se o backend não mandar isto

                    $checkbox.on('change', updateRemoveState);

                    $row.append($checkbox, $icon, $name, $downloadLink, $viewLink, $meta);
                    $body.append($row);
                });

                updateRemoveState();
            }

            // ===== CARREGAR LISTA =====
            function loadFiles() {
                if (!referenceId) {
                    files = [];
                    renderList();
                    return;
                }
                $.ajax({
                    url: settings.listUrl,
                    method: 'GET',
                    data: { id: referenceId, category: settings.category },
                    dataType: 'json'
                }).done(function (response) {
                    files = (response && response.success && Array.isArray(response.files)) ? response.files : [];
                    renderList();
                    if (settings.onChange) settings.onChange(files);
                }).fail(function () {
                    $body.empty().append(
                        $('<div></div>').css({ 'text-align': 'center', 'color': '#dc3545', 'padding': '30px 20px', 'font-size': '13px' })
                            .text('Não foi possível carregar os anexos.')
                    );
                });
            }

            // ===== ABRIR / FECHAR =====
            // open(id) aceita o id NO MOMENTO de abrir — é o que permite UMA instância
            // servir várias linhas de uma tabela, cada uma com o seu próprio botão
            // "Anexos" (data-id a variar por clique, tal como já fazes no
            // .btnUploadFile). Se não passares nada, usa o que já estiver definido
            // (por settings.referenceId ou por setReferenceId anterior).
            function open(id) {
                if (id !== undefined && id !== null && id !== '') referenceId = id;
                loadFiles();
                $backdrop.css('display', 'flex');
            }
            function close() {
                $backdrop.css('display', 'none');
            }

            $trigger.on('click', function () { open(); }); // gatilho automático: usa o referenceId já definido, sem override
            $closeBtn.on('click', close);
            $backdrop.on('click', function (e) { if (e.target === this) close(); });

            // ===== ESCOLHER FICHEIRO → UPLOAD IMEDIATO =====
            $chooseBtn.on('click', function () {
                if (files.length >= maxFiles) {
                    alert('Limite de ' + maxFiles + ' anexo(s) atingido.');
                    return;
                }
                $fileInput.click();
            });

            $fileInput.on('change', function (e) {
                var selected = Array.prototype.slice.call(e.target.files || []);
                $(this).val('');
                if (selected.length === 0) return;

                if (!settings.multiple) selected = selected.slice(0, 1);

                var remaining = maxFiles - files.length;
                if (remaining <= 0) {
                    alert('Limite de ' + maxFiles + ' anexo(s) atingido.');
                    return;
                }
                selected = selected.slice(0, remaining);

                selected.forEach(function (file) {
                    var formData = new FormData();
                    formData.append('file', file);
                    formData.append('id', referenceId);
                    if (settings.category) formData.append('category', settings.category);

                    $chooseBtn.prop('disabled', true).text('A enviar...');

                    $.ajax({
                        url: settings.uploadUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json'
                    }).done(function (response) {
                        if (response && response.success) {
                            loadFiles();
                        } else {
                            alert((response && response.message) || 'Falha ao enviar o ficheiro.');
                        }
                    }).fail(function () {
                        alert('Falha ao enviar o ficheiro.');
                    }).always(function () {
                        $chooseBtn.prop('disabled', false).text('Choose file');
                    });
                });
            });

            // ===== REMOVER (bulk) =====
            $removeBtn.on('click', function () {
                var ids = $body.find('.upload-file-checkbox:checked').map(function () {
                    return $(this).closest('.upload-file-row').data('id');
                }).get();

                if (ids.length === 0) return;
                if (settings.confirmRemove && !confirm('Remover ' + ids.length + ' anexo(s)? Esta acção não pode ser desfeita.')) return;

                $removeBtn.prop('disabled', true).text('A remover...');

                $.ajax({
                    url: settings.deleteUrl,
                    method: 'POST',
                    data: { ids: ids, id: referenceId, category: settings.category },
                    dataType: 'json'
                }).done(function (response) {
                    if (response && response.success) {
                        loadFiles();
                    } else {
                        alert((response && response.message) || 'Falha ao remover.');
                    }
                }).fail(function () {
                    alert('Falha ao remover.');
                }).always(function () {
                    $removeBtn.text('Remove');
                    updateRemoveState();
                });
            });

            // ===== DOWNLOAD ALL =====
            $downloadAllBtn.on('click', function () {
                if (files.length === 0) return;

                if (settings.downloadAllUrl) {
                    // Zip gerado no servidor — um download só. Navegação directa (não AJAX),
                    // porque o browser precisa de tratar a resposta como ficheiro a descarregar.
                    var params = $.param({ id: referenceId, category: settings.category });
                    window.location.href = settings.downloadAllUrl + (settings.downloadAllUrl.indexOf('?') > -1 ? '&' : '?') + params;
                    return;
                }

                // Sem downloadAllUrl configurado: mantém o comportamento anterior
                // (um <a download> por ficheiro, com intervalo entre cada).
                files.forEach(function (file, index) {
                    setTimeout(function () {
                        var $a = $('<a></a>').attr({ href: file.url, download: file.name || 'ficheiro' }).css('display', 'none');
                        $('body').append($a);
                        $a[0].click();
                        $a.remove();
                    }, index * 200); // pequeno intervalo entre cada — disparar tudo no mesmo instante faz vários browsers bloquearem downloads múltiplos como se fosse pop-up
                });
            });

            // ===== API PÚBLICA =====
            var instance = {
                open: open,
                close: close,
                refresh: loadFiles,
                setReferenceId: function (id) {
                    referenceId = id;
                },
                destroy: function () {
                    $backdrop.remove();
                    $trigger.remove();
                    $original.show();
                    $original.removeData('uploadFile');
                }
            };

            $original.data('uploadFile', instance);

            if (settings.onReady) settings.onReady(instance);
        });
    };
})(jQuery);
