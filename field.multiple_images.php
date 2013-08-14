<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Upload Multiple Images Field Type, 
 *
 * @package		PyroCMS\Core\Modules\Streams Core\Field Types
 * @author		Rigo B Castro
 * @copyright           Copyright (c) 2011 - 2013, Rigo B Castro
 * @license		http://parse19.com/pyrostreams/docs/license
 * @link		https://github.com/WeDreamPro/PyroCMS-Multiple-Images-FieldType
 */
class Field_multiple_images {

    public $field_type_slug = 'multiple_images';
    public $db_col_type = false;
    public $custom_parameters = array('folder', 'table_name', 'resource_id_column', 'file_id_column', 'max_limit_images');
    public $version = '1.1.0';
    public $author = array('name' => 'Rigo B Castro', 'url' => 'http://rigobcastro.com');

    // --------------------------------------------------------------------------

    /**
     * Run time cache
     */
    private $cache;

    // --------------------------------------------------------------------------

    public function event($field)
    {
        $this->CI->type->add_misc('<link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css" rel="stylesheet">');
        $this->CI->type->add_misc('<script src="//cdnjs.cloudflare.com/ajax/libs/handlebars.js/1.0.0/handlebars.min.js"></script>');

        $this->CI->type->add_css('multiple_images', 'style.css');
        $this->CI->type->add_js('multiple_images', 'browserplus-min.js');
        $this->CI->type->add_js('multiple_images', 'plupload.full.js');
    }

    /**
     * Output form input
     *
     * @param	array
     * @param	array
     * @return	string
     */
    public function form_output($data, $entry_id, $field)
    {
        
        $this->_clean_files($field);

        $upload_url = site_url('admin/files/upload');

        $data = array(
            'multipart_params' => array(
                $this->CI->security->get_csrf_token_name() => $this->CI->security->get_csrf_hash(),
                'folder_id' => $field->field_data['folder'],
            ),
            'upload_url' => $upload_url,
            'is_new' => empty($entry_id)
        );

        if (!empty($entry_id))
        {
            $table_data = $this->_table_data($field);
            $images_out = array();

            $this->CI->db->join('files as F', "F.id = {$table_data->table}.{$table_data->file_id_column}");

            $images = $this->CI->db->order_by('F.sort', 'ASC')->get_where($table_data->table, array(
                    $table_data->resource_id_column => $entry_id
                ))->result();

            if (!empty($images))
            {

                foreach ($images as $image)
                {
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


        return $this->CI->type->load_view('multiple_images', 'plupload_js', $data);
    }

    // --------------------------------------------------------------------------

    public function pre_save($images, $field, $stream, $row_id, $data_form)
    {
        $table_data = $this->_table_data($field);
        $table = $table_data->table;
        $resource_id_column = $table_data->resource_id_column;
        $file_id_column = $table_data->file_id_column;
        $max_limit_images = (int) $field->field_data['max_limit_images'];
        
        if (!empty($max_limit_images))
        {
            if (count($images) > $max_limit_images)
            {
                $this->CI->session->set_flashdata('notice', sprintf(lang('streams:multiple_images.max_limit_error'), $max_limit_images));
            }
        }

        if ($this->CI->db->table_exists($table))
        {
            $this->CI->db->trans_begin();

            // Reset
            if ($this->CI->db->delete($table, array($resource_id_column, $row_id)))
            {
                $count = 1;
                // Insert new images
                foreach ($images as $file_id)
                {
                    $check = !empty($max_limit_images) ? $count <= $max_limit_images : true;
                    
                    if ($check)
                    {
                        if (!$this->CI->db->insert($table, array(
                                $resource_id_column => $row_id,
                                $file_id_column => $file_id
                            )))
                        {
                            $this->CI->session->set_flashdata('error', 'Error al guardar las nuevas imagenes');
                            return false;
                        }
                    }
                    
                    $count++;
                }
            }

            if ($this->CI->db->trans_status() === FALSE)
            {
                $this->CI->db->trans_rollback();
                $this->CI->session->set_flashdata('error', 'Error al guardar las nuevas imagenes');
                return false;
            }
            else
            {
                $this->CI->db->trans_commit();
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Pre Ouput
     *
     * Process before outputting on the CP. Since
     * there is less need for performance on the back end,
     * this is accomplished via just grabbing the title column
     * and the id and displaying a link (ie, no joins here).
     *
     * @access	public
     * @param	array 	$input 	
     * @return	mixed 	null or string
     */
    public function pre_output($input, $data)
    {
        if (!$input)
            return null;

        $stream = $this->CI->streams_m->get_stream($data['choose_stream']);

        $title_column = $stream->title_column;

        // -------------------------------------
        // Data Checks
        // -------------------------------------
        // Make sure the table exists still. If it was deleted we don't want to
        // have everything go to hell.
        if (!$this->CI->db->table_exists($stream->stream_prefix . $stream->stream_slug))
        {
            return null;
        }

        // We need to make sure the select is NOT NULL.
        // So, if we have no title column, let's use the id
        if (trim($title_column) == '')
        {
            $title_column = 'id';
        }

        // -------------------------------------
        // Get the entry
        // -------------------------------------

        $row = $this->CI->db
            ->select()
            ->where('id', $input)
            ->get($stream->stream_prefix . $stream->stream_slug)
            ->row_array();

        if ($this->CI->uri->segment(1) == 'admin')
        {
            if (isset($data['link_uri']) and !empty($data['link_uri']))
            {
                return '<a href="' . site_url(str_replace(array('-id-', '-stream-'), array($row['id'], $stream->stream_slug), $data['link_uri'])) . '">' . $row[$title_column] . '</a>';
            }
            else
            {
                return '<a href="' . site_url('admin/streams/entries/view/' . $stream->id . '/' . $row['id']) . '">' . $row[$title_column] . '</a>';
            }
        }
        else
        {
            return $row;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Pre Ouput Plugin
     * 
     * This takes the data from the join array
     * and formats it using the row parser.
     *
     * @access	public
     * @param	array 	$row 		the row data from the join
     * @param	array  	$custom 	custom field data
     * @param	mixed 	null or formatted array
     */
    public function pre_output_plugin($row, $custom)
    {
        if (!$row)
            return null;

        // Mini-cache for getting the related stream.
        if (isset($this->cache[$custom['choose_stream']][$row]))
        {
            return $this->cache[$custom['choose_stream']][$row];
        }

        // Okay good to go
        $stream = $this->CI->streams_m->get_stream($custom['choose_stream']);

        // Do this gracefully
        if (!$stream)
        {
            return null;
        }

        $stream_fields = $this->CI->streams_m->get_stream_fields($stream->id);

        // We should do something with this in the future.
        $disable = array();

        return $this->CI->row_m->format_row($row, $stream_fields, $stream, false, true, $disable);
    }

    // ----------------------------------------------------------------------
    
    /**
     * Choose a folder to upload to.
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_folder($value = null)
    {
        // Get the folders
        $this->CI->load->model('files/file_folders_m');

        $tree = $this->CI->file_folders_m->get_folders();

        $tree = (array) $tree;

        if (!$tree)
        {
            return '<em>' . lang('streams:file.folder_notice') . '</em>';
        }

        $choices = array();

        foreach ($tree as $tree_item)
        {
            // We are doing this to be backwards compat
            // with PyroStreams 1.1 and below where
            // This is an array, not an object
            $tree_item = (object) $tree_item;

            $choices[$tree_item->id] = $tree_item->name;
        }

        return form_dropdown('folder', $choices, $value);
    }

    // --------------------------------------------------------------------------
    
     // --------------------------------------------------------------------------

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_table_name($value = null)
    {
        $tables = get_instance()->db->list_tables();
        $tables_dropdown = array();

        foreach ($tables as $table)
        {
            $prefix = explode('_', $table);
            if ($prefix[0] !== 'core')
            {
                $tables_dropdown[$table] = $table;
            }
        }

        return array(
            'input' => form_dropdown('choice_table_name', $tables_dropdown, $value),
            'instructions' => $this->CI->lang->line('streams:choice_db.instructions_tablename')
        );
    }

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_resource_id_column($value = null)
    {

        return form_input(array(
            'name' => 'resource_id_column',
            'value' => !empty($value) ? $value : 'resource_id',
            'type' => 'text'
        ));
    }
    
    // --------------------------------------------------------------------------
    
    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_file_id_column($value = null)
    {

        return form_input(array(
            'name' => 'file_id_column',
            'value' => !empty($value) ? $value : 'file_id',
            'type' => 'text'
        ));
    }

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_max_limit_images($value = null)
    {

        return form_input(array(
            'name' => 'max_limit_images',
            'value' => !empty($value) ? $value : 5,
            'type' => 'text'
        ));
    }

    private function _table_data($field)
    {
        return (object) array(
                'table' => (!empty($field->field_data['table_name']) ? $field->field_data['table_name'] : "{$field->stream_slug}_{$field->field_slug}"),
                'resource_id_column' => $field->field_data['resource_id_column'],
                'file_id_column' => (!empty($field->field_data['file_id_column']) ? $field->field_data['file_id_column'] : 'file_id')
        );
    }

    // ----------------------------------------------------------------------

    private function _clean_files($field)
    {
        $table_data = $this->_table_data($field);

        $content = Files::folder_contents($field->field_data['folder']);
        $files = $content['data']['file'];
        $valid_files = $this->CI->db->select($table_data->file_id_column . ' as id')->from($table_data->table)->get()->result();
        $valid_files_ids = array();

        if (!empty($valid_files))
        {
            foreach ($valid_files as $vf)
            {
                array_push($valid_files_ids, $vf->id);
            }
        }

        if (!empty($files))
        {
            foreach ($files as $file)
            {
                if (!in_array($file->id, $valid_files_ids))
                {
                    Files::delete_file($file->id);
                }
            }
        }
    }

    // ----------------------------------------------------------------------
}
