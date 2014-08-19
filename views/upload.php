<?php if($this->uri->segment(1) !== 'admin'): ?>
    <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="<?=base_url('streams_core/field_asset/js/multiple_images/style.css');?>" rel="stylesheet">
    <script type="text/javascript" src="<?=base_url('streams_core/field_asset/js/multiple_images/plupload.full.min.js');?>"></script>
    <script type="text/javascript" src="<?=base_url('streams_core/field_asset/js/multiple_images/handlebars.js');?>"></script>
<?php endif; ?>
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
<div style="clear: both"></div>

<script id="image-template" type="text/x-handlebars-template">
    <div id="file-{{id}}" class="thumb {{#unless is_new}} load {{/unless}}">
    <div class="image-preview">

    {{#if is_new}}
    <div class="loading-multiple-images loading-multiple-images-spin-medium" style="position:absolute; z-index: 9999; left:40%; top:25%"></div>
    {{/if}}

    <a class="image-link" href="{{url}}" rel="multiple_images"><img src="{{url}}" alt="{{name}}" /></a>
    <input class="images-input" type="hidden" name="<?php echo $field_slug ?>[]" value="{{id}}" />
    <a class="delete-image" href="#">
    <span class="fa fa-trash-o"></span>
    </a>   
    </div>

    </div>
</script>

<script>
    $(function() {

        var nativeFiles = {},
                isHTML5 = false,
                $image_template = Handlebars.compile($('#image-template').html()),
                $images_list = $('#multiple-images-gallery'),
                entry_is_new = <?= json_encode($is_new) ?>,
                images = <?= json_encode($images) ?>;

        var uploader = new plupload.Uploader({
            runtimes: 'html5,flash',
            browse_button: 'drop-target',
            drop_element: 'drop-target',
            container: 'upload-container',
            max_file_size: '<?= Settings::get('files_upload_limit') ?>mb',
            url: <?= json_encode($upload_url) ?>,
            flash_swf_url: '/plupload/js/Moxie.swf',
            silverlight_xap_url: '/plupload/js/Moxie.xap',
            filters: [
                {title: "Image files", extensions: "jpg,gif,png,jpeg,tiff"}
            ],
            resize: {quality: 90},
            multipart_params: <?= json_encode($multipart_params) ?>,
            init: {
                PostInit: function() {
                    isHTML5 = uploader.runtime === "html5";
                    if (isHTML5) {

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
                },
                FilesAdded: function(up, files) {
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
                            })(file, file.id);
                            reader.readAsDataURL(file.getNative());
                        } else {
                            $('#filelist').append('<div id="' + file.id + '">' + file.name + ' (' + plupload.formatSize(file.size) + ') <b></b>' + '</div>');
                        }
                    });
                    uploader.start();
                    up.refresh();
                },
                UploadProgress: function(up, file) {
                    $file(file.id).find('img').css({opacity: file.percent / 100});

                    /* Prevent close while upload */
                    $(window).on('beforeunload', function() {
                        return 'Hay una subida en progreso...';
                    });
                },
                Error: function(up, error) {
                    if (typeof (pyro) !== "undefined") {
                        pyro.add_notification('<div class="alert error"><p><?= lang('streams:multiple_images.adding_error') ?></p></div>');
                    }
                    up.refresh();
                },
                FileUploaded: function(up, file, info) {
                    var response = JSON.parse(info.response);
                    if (response.status === false) {
                        $file(file.id).remove();
                        if (typeof (pyro) !== "undefined") {
                            pyro.add_notification('<div class="alert error"><p>' + response.message + '</p></div>');
                        }
                        $(window).off('beforeunload');
                        return false;
                    }
                    $file(file.id).addClass('load').find('.images-input').val(response.data.id);
                    $file(file.id).find('.image-link').attr('href', response.data.path.replace("{{ url:site }}", SITE_URL));
                    $file(file.id).find('.loading-multiple-images').remove();

                    /* Off: Prevent close while upload */
                    $(window).off('beforeunload');
                }
            }
        });

        uploader.init();

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
        if (typeof ($.sortable) !== "undefined") {
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
        }
    });
</script>