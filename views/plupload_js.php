<div id="upload-container">
    <div id="drop-target">
        <div class="drop-area" style="display: none;">
            <span><?php echo lang('streams:multiple_images.help_draganddrop') ?></span>
            <span style="display: none;"><?php echo lang('streams:multiple_images.drop_images_here') ?></span>
        </div>
        <div class="no-drop-area" style="display: none;">
            <a href="#" class="btn blue"><?php echo lang('streams:multiple_images.select_files'); ?></a>
        </div>
    </div>
</div>

<div id="multiple-images-gallery"></div>

<script id="image-template" type="text/x-handlebars-template">
    <div id="file-{{id}}" class="thumb {{#unless is_new}} load {{/unless}}">
    <div class="image-preview">
    <a class="image-link" href="{{url}}" rel="multiple_images"><img src="{{url}}" alt="{{name}}" /></a>
    <input class="images-input" type="hidden" name="images[]" value="{{id}}" />
    <a class="delete-image" href="#"><i class="icon-remove icon-large"></i></a>   
    </div>
    </div>
</script>

<script>
    $(function() {
        var uploader = new plupload.Uploader({
            runtimes: 'gears,html5,flash,silverlight,browserplus',
            browse_button: 'drop-target',
            drop_element: 'drop-target',
            container: 'upload-container',
            max_file_size: '10mb',
            url: <?= json_encode($upload_url) ?>,
            flash_swf_url: '/plupload/js/plupload.flash.swf',
            silverlight_xap_url: '/plupload/js/plupload.silverlight.xap',
            filters: [
                {title: "Image files", extensions: "jpg,gif,png,jpeg,tiff"}
            ],
            resize: {quality: 90},
            multipart_params: <?= json_encode($multipart_params) ?>
        });

        var nativeFiles = {},
            isHTML5 = false,
            $image_template = Handlebars.compile($('#image-template').html()),
            $images_list = $('#multiple-images-gallery'),
            entry_is_new = <?= json_encode($is_new) ?>,
            images = <?= json_encode($images) ?>;

        uploader.bind('PostInit', function() {
            isHTML5 = uploader.runtime === "html5";
            if (isHTML5) {
                var inputFile = document.getElementById(uploader.id + '_html5');
                var oldFunction = inputFile.onchange;

                inputFile.onchange = function() {
                    nativeFiles = this.files;
                    oldFunction.call(inputFile);
                }

                $('#drop-target').addClass('html5').on({
                    drop: function(e) {
                        var files = e.originalEvent.dataTransfer.files;
                        nativeFiles = files;

                        return $(this).removeClass('dragenter').find('.drop-area span:last').hide().prev().show();
                    }
                });

                $('body').on({
                    dragenter: function() {
                        return $('#drop-target').addClass('dragenter').find('.drop-area span:first').hide().next().show();
                    },
                    dragleave: function() {
                        return $('#drop-target').removeClass('dragenter').find('.drop-area span:last').hide().prev().show();
                    }
                });

                $('.drop-area').show();
            } else {
                $('.no-drop-area').show();
            }
        });

        uploader.bind('Init', function(up, params) {
            console.log("Current runtime: " + params.runtime);
        });

        uploader.init();

        uploader.bind('FilesAdded', function(up, files) {

            $.each(files, function(i, file) {
                if (isHTML5) {
                    var reader = new FileReader();

                    reader.onload = (function(file, id) {
                        return function(e) {
                            return add_image({
                                id: id,
                                url: e.target.result,
                                is_new: true
                            });
                        };
                    })(nativeFiles[i], file.id);

                    reader.readAsDataURL(nativeFiles[i]);
                } else {
                    $('#filelist').append('<div id="' + file.id + '">' + file.name + ' (' + plupload.formatSize(file.size) + ') <b></b>' + '</div>');
                }
            });

            uploader.start();

            up.refresh();
        });

        uploader.bind('UploadProgress', function(up, file) {
            $file(file.id).find('img').css({opacity: file.percent / 100});

            /* Prevent close while upload */
            $(window).on('beforeunload', function() {
                return 'Hay una subida en progreso...';
            });
        });

        uploader.bind('Error', function(up, error) {
            pyro.add_notification('<div class="alert error"><p><?= lang('streams:multiple_images.adding_error') ?></p></div>');
            up.refresh();
        });

        uploader.bind('FileUploaded', function(up, file, info) {
            var response = JSON.parse(info.response);
            $file(file.id).addClass('load').find('.images-input').val(response.data.id);
            $file(file.id).find('.image-link').attr('href', response.data.path.replace("{{ url:site }}", SITE_URL));

            /* Off: Prevent close while upload */
            $(window).off('beforeunload');
        });


        /* Private methods */

        function $file(id) {
            return $('#file-' + id);
        }

        function add_image(data) {
            return $images_list.append($image_template(data));
        }

        if (entry_is_new === false && images) {
            for (var i in images) {
                add_image(images[i]);
            }
        }

        /* Events! */

        $(document).on('click', '.image-link', function() {
            $.colorbox({href: this.href, open: true});
            return false;
        });

        $(document).on('click', '.delete-image', function(e) {
            var $this = $(this),
                file_id = $this.parent().find('input.images-input').val();

            if (confirm(pyro.lang.dialog_message)) {
                $.post(SITE_URL + 'admin/files/delete_file', {file_id: file_id}, function(json) {
                    if (json.status === true) {
                        $this.parents('.thumb').fadeOut(function() {
                            return $(this).remove();
                        });
                    } else {
                        alert(json.message);
                    }
                }, 'json');
            }

            return e.preventDefault();
        });

        $("#multiple-images-gallery").sortable({
            cursor: 'move',
            placeholder: "sortable-placeholder",
            update: function() {
                var sortedIDs = $(this).sortable("toArray"),
                    data = {order: {files: []}};

                for (var id in sortedIDs) {
                    data.order.files.push(sortedIDs[id].replace('file-', ''));
                }

                $.post(SITE_URL + 'admin/files/order', data, function(json) {
                    if (json.status === false) {
                        alert(json.message);
                    }
                }, 'json');
            }
        }).disableSelection();
    });
</script>