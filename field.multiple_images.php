<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Upload Multiple Images Field Type, 
 *
 * @author		Rigo B Castro
 * @author      Jose Fonseca
 * @copyright           Copyright (c) 2011 - 2013, We dream pro
 * @link		https://github.com/WeDreamPro/PyroCMS-Multiple-Images-FieldType
 */
class Field_multiple_images {

    public $field_type_slug = 'multiple_images';
    public $alt_process = true;
    public $db_col_type = false;
    public $custom_parameters = array('folder', 'max_limit_images');
    public $version = '1.4.0';
    public $author = array('name' => 'We dream Pro', 'url' => 'http://wedreampro.com');

    // --------------------------------------------------------------------------

    /**
     * Run time cache
     */
    private $cache;

    // --------------------------------------------------------------------------

    public function event($field) {
        $this->CI->type->add_misc('<link href="//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">');
        $this->CI->type->add_css('multiple_images', 'style.css');
        $this->CI->type->add_js('multiple_images', 'plupload.full.min.js');
        $this->CI->type->add_js('multiple_images', 'handlebars.js');
    }

    /**
     * Output form input
     *
     * @param	array
     * @param	array
     * @return	string
     */
    public function form_output($data, $entry_id, $field) {
        $this->CI->load->library('files/files');
        $this->_clean_files($field);
        $upload_url = site_url('admin/files/upload');
        $data = array(
            'multipart_params' => array(
                $this->CI->security->get_csrf_token_name() => $this->CI->security->get_csrf_hash(),
                'folder_id' => $field->field_data['folder'],
            ),
            'upload_url' => $upload_url,
            'is_new' => empty($entry_id),
            'max_limit_images' => $field->field_data['max_limit_images']
        );
        if (!empty($entry_id)) {
            $table_data = $this->_table_data($field);
            $images_out = array();
            $this->CI->db->join('files as F', "F.id = {$table_data->table}.{$table_data->file_id_column}");
            $images = $this->CI->db->order_by('F.sort', 'ASC')->get_where($table_data->table, array(
                        $table_data->resource_id_column => $entry_id
                    ))->result();
            if (!empty($images)) {
                foreach ($images as $image) {
                    $images_out[] = array(
                        'id' => $image->{$table_data->file_id_column},
                        'name' => $image->name,
                        'url' => str_replace('{{ url:site }}', base_url(), $image->path),
                        'is_new' => false
                    );
                }
                $data['images'] = $images_out;
            }
        }
        $data['field_slug'] = $field->field_slug;
        return $this->CI->type->load_view('multiple_images', 'upload', $data);
    }

    // --------------------------------------------------------------------------

    public function pre_save($images, $field, $stream, $row_id, $data_form) {
        $table_data = $this->_table_data($field);
        $table = $table_data->table;
        $resource_id_column = $table_data->resource_id_column;
        $file_id_column = $table_data->file_id_column;
        $max_limit_images = (int) $field->field_data['max_limit_images'];
        if (!empty($max_limit_images)) {
            if (count($images) > $max_limit_images) {
                $this->CI->session->set_flashdata('notice', sprintf(lang('streams:multiple_images.max_limit_error'), $max_limit_images));
            }
        }
        if ($this->CI->db->table_exists($table)) {
            $this->CI->db->trans_begin();
            // Reset
            if (!empty($row_id)) {
                $this->CI->db->delete($table, array($resource_id_column => (int) $row_id));
            }
            $count = 1;
            // Insert new images
            foreach ($images as $file_id) {
                $check = !empty($max_limit_images) ? $count <= $max_limit_images : true;
                if ($check) {
                    if (!$this->CI->db->insert($table, array(
                                $resource_id_column => $row_id,
                                $file_id_column => $file_id
                            ))) {
                        $this->CI->session->set_flashdata('error', 'Error al guardar las nuevas imagenes');
                        return false;
                    }
                }
                $count++;
            }
            if ($this->CI->db->trans_status() === FALSE) {
                $this->CI->db->trans_rollback();
                $this->CI->session->set_flashdata('error', 'Error al guardar las nuevas imagenes');
                return false;
            } else {
                $this->CI->db->trans_commit();
            }
        }
    }

    /**
     * User Field Type Query Build Hook
     *
     * This joins our user fields.
     *
     * @access 	public
     * @param 	array 	&$sql 	The sql array to add to.
     * @param 	obj 	$field 	The field obj
     * @param 	obj 	$stream The stream object
     * @return 	void
     */
    public function query_build_hook(&$sql, $field, $stream) {
        $table = $this->_table_data($field);
        $sql['select'][] = $this->CI->db->protect_identifiers($stream->stream_prefix . $stream->stream_slug . '.id', true) . "as `" . $field->field_slug . "||{$table->resource_id_column}`";
    }

    // --------------------------------------------------------------------------

    /** Alt Pre output * */
    public function alt_pre_output($row_id, $params, $field_type, $stream) {
        if ($this->CI->uri->segment(1) == 'admin') {
            return false;
        }
        $table = $this->_table_data((object) $params);
        $file_id_column = !empty($table->file_id_column) ? $table->file_id_column : 'file_id';
        $resource_id_column = !empty($table->resource_id) ? $table->resource_id : 'resource_id';
        $images = $this->CI->db->where($resource_id_column, (int) $row_id)->get($table->table)->result_array();
        $return = array();
        if (!empty($images)) {
            foreach ($images as &$image) {
                $this->CI->load->library('files/files');
                $file_id = $image[$file_id_column];
                $file = Files::get_file($file_id);
                $image_data = array();
                if ($file['status']) {
                    $image = $file['data'];
                    // If we don't have a path variable, we must have an
                    // older style image, so let's create a local file path.
                    if (!$image->path) {
                        $image_data['image'] = base_url($this->CI->config->item('files:path') . $image->filename);
                    } else {
                        $image_data['image'] = str_replace('{{ url:site }}', base_url(), $image->path);
                    }
                    // For <img> tags only
                    $alt = $this->obvious_alt($image);
                    $image_data['filename'] = $image->filename;
                    $image_data['name'] = $image->name;
                    $image_data['alt'] = $image->alt_attribute;
                    $image_data['description'] = $image->description;
                    $image_data['img'] = img(array('alt' => $alt, 'src' => $image_data['image']));
                    $image_data['ext'] = $image->extension;
                    $image_data['mimetype'] = $image->mimetype;
                    $image_data['width'] = $image->width;
                    $image_data['height'] = $image->height;
                    $image_data['id'] = $image->id;
                    $image_data['filesize'] = $image->filesize;
                    $image_data['download_count'] = $image->download_count;
                    $image_data['date_added'] = $image->date_added;
                    $image_data['folder_id'] = $image->folder_id;
                    $image_data['folder_name'] = $image->folder_name;
                    $image_data['folder_slug'] = $image->folder_slug;
                    $image_data['thumb'] = site_url('files/thumb/' . $file_id);
                    $image_data['thumb_img'] = img(array('alt' => $alt, 'src' => site_url('files/thumb/' . $file_id)));
                }
                $return[] = $image_data;
            }
        }
        return $return;
    }

    // ----------------------------------------------------------------------

    /**
     * Choose a folder to upload to.
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_folder($value = null) {
        // Get the folders
        $this->CI->load->model('files/file_folders_m');
        $tree = $this->CI->file_folders_m->get_folders();
        $tree = (array) $tree;
        if (!$tree) {
            return '<em>' . lang('streams:file.folder_notice') . '</em>';
        }
        $choices = array();
        foreach ($tree as $tree_item) {
            // We are doing this to be backwards compat
            // with PyroStreams 1.1 and below where
            // This is an array, not an object
            $tree_item = (object) $tree_item;
            $choices[$tree_item->id] = $tree_item->name;
        }
        return form_dropdown('folder', $choices, $value);
    }

    // --------------------------------------------------------------------------

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_max_limit_images($value = null) {

        return form_input(array(
                    'name' => 'max_limit_images',
                    'value' => !empty($value) ? $value : 5,
                    'type' => 'text'
                ));
    }

    // ----------------------------------------------------------------------

    /**
     * Obvious alt attribute for <img> tags only
     *
     * @access	private
     * @param	obj
     * @return	string
     */
    private function obvious_alt($image) {
        if ($image->alt_attribute) {
            return $image->alt_attribute;
        }
        if ($image->description) {
            return $image->description;
        }
        return $image->name;
    }

    // ----------------------------------------------------------------------

    private function _table_data($field, $stream = null) {
        if (empty($stream)) {
            $steam_slug = $field->stream_slug;
        } else {
            $steam_slug = $stream->stream_slug;
        }
        return (object) array(
                    'table' => (!empty($field->field_data['table_name']) ? $field->field_data['table_name'] : "{$steam_slug}_{$field->field_slug}"),
                    'resource_id_column' => (!empty($field->field_data['resource_id_column'])) ? $field->field_data['resource_id_column'] : 'resource_id',
                    'file_id_column' => (!empty($field->field_data['file_id_column']) ? $field->field_data['file_id_column'] : 'file_id')
        );
    }

    // ----------------------------------------------------------------------

    private function _clean_files($field, $stream = null) {
        /**
         * This methid is under review
         */
        /**$this->CI->load->library('files/files');
        $table_data = $this->_table_data($field, $stream);
        $content = Files::folder_contents($field->field_data['folder']);
        $files = $content['data']['file'];
        $valid_files = $this->CI->db->select($table_data->file_id_column . ' as id')->from($table_data->table)->get()->result();
        $valid_files_ids = array();
        if (!empty($valid_files)) {
            foreach ($valid_files as $vf) {
                array_push($valid_files_ids, $vf->id);
            }
        }
        if (!empty($files)) {
            foreach ($files as $file) {
                if (!in_array($file->id, $valid_files_ids)) {
                    Files::delete_file($file->id);
                }
            }
        }**/
    }

    // ----------------------------------------------------------------------

    public function entry_destruct($entry, $field, $stream) {
        $this->CI->load->library('files/files');
        $table_data = $this->_table_data($field, $stream);
        /** first lets get the images that we need to delete * */
        $rows = $this->CI->db->where($table_data->resource_id_column, $entry->id)->get($table_data->table)->result();
        /** lets remove the files * */
        foreach ($rows as $r) {
            Files::delete_file($r->{$table_data->file_id_column});
        }
        /** Now lets delete the entries in the table * */
        $this->CI->db->where($table_data->resource_id_column, $entry->id)->delete($table_data->table);
    }

    /**
     * Deletes the table and clean files
     * @param type $field
     * @param type $stream
     */
    public function field_assignment_destruct($field, $stream) {
        $this->CI->load->library('files/files');
        $table_data = $this->_table_data($field, $stream);
        /** first lets get the images that we need to delete * */
        $rows = $this->CI->db->get($table_data->table)->result();
        /** lets remove the files * */
        foreach ($rows as $r) {
            Files::delete_file($r->{$table_data->file_id_column});
        }
        /** Drop the table! * */
        $this->CI->dbforge->drop_table($table_data->table);
    }

    // ----------------------------------------------------------------------
    /**
     * Creates the necesary table for the images to be stored
     * @param type $field
     * @param type $stream
     */
    public function field_assignment_construct($field, $stream) {
        $table_data = $this->_table_data($field, $stream);
        $table = $table_data->table;
        $resource_id_column = $table_data->resource_id_column;
        $file_id_column = $table_data->file_id_column;
        /**
         * If the table to store the images does not exists, please create it
         */
        if (!$this->CI->db->table_exists($table)) {
            $fields = array(
                $resource_id_column => array(
                    'type' => 'VARCHAR',
                    'constraint' => '100',
                ),
                $file_id_column => array(
                    'type' => 'VARCHAR',
                    'constraint' => '100',
                )
            );
            $this->CI->dbforge->add_field($fields);
            $this->CI->dbforge->create_table($table, TRUE);
        }
    }
}