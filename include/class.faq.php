<?php
/*********************************************************************
    class.faq.php

    Backend support for article creates, edits, deletes, and attachments.

    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once('class.file.php');
require_once('class.category.php');

class FAQ {

    var $id;
    var $ht;

    var $category;
    var $attachments;

    function FAQ($id) {
        $this->id=0;
        $this->ht = array();
        $this->load($id);
    }

    function load($id) {

        $sql='SELECT faq.*,cat.ispublic, count(attach.file_id) as attachments '
            .' FROM '.FAQ_TABLE.' faq '
            .' LEFT JOIN '.FAQ_CATEGORY_TABLE.' cat ON(cat.category_id=faq.category_id) '
            .' LEFT JOIN '.ATTACHMENT_TABLE.' attach
                 ON(attach.object_id=faq.faq_id AND attach.`type`=\'F\' AND attach.inline=0) '
            .' WHERE faq.faq_id='.db_input($id)
            .' GROUP BY faq.faq_id';

        if (!($res=db_query($sql)) || !db_num_rows($res))
            return false;

        $this->ht = db_fetch_array($res);
        $this->ht['id'] = $this->id = $this->ht['faq_id'];
        $this->category = null;
        $this->attachments = new GenericAttachments($this->id, 'F');

        return true;
    }

    function reload() {
        return $this->load($this->getId());
    }

    /* ------------------> Getter methods <--------------------- */
    function getId() { return $this->id; }
    function getHashtable() { return $this->ht; }
    function getKeywords() { return $this->ht['keywords']; }
    function getQuestion() { return $this->ht['question']; }
    function getAnswer() { return $this->ht['answer']; }
    function getAnswerWithImages() {
        return Format::viewableImages($this->ht['answer'], ROOT_PATH.'image.php');
    }
    function getNotes() { return $this->ht['notes']; }
    function getNumAttachments() { return $this->ht['attachments']; }

    function isPublished() { return (!!$this->ht['ispublished'] && !!$this->ht['ispublic']); }

    function getCreateDate() { return $this->ht['created']; }
    function getUpdateDate() { return $this->ht['updated']; }

    function getCategoryId() { return $this->ht['category_id']; }
    function getCategory() {
        if(!$this->category && $this->getCategoryId())
            $this->category = Category::lookup($this->getCategoryId());

        return $this->category;
    }

    function getCollectionsIds() {

        if (!isset($this->ht['collections']) && ($collections=$this->getCollections())) {
            $this->ht['collections'] = array_keys($collections);
        }

        return $this->ht['collections'];
    }

    function getCollections() {
        //XXX: change it to obj (when needed)!

        if (!isset($this->collections)) {
            $this->collections = array();
            $sql='SELECT t.collection_id, CONCAT_WS(" / ", pt.collection, t.collection) as name, t.color as color  FROM '.COLLECTION_TABLE.' t '
                .' INNER JOIN '.FAQ_COLLECTION_TABLE.' ft ON(ft.collection_id=t.collection_id AND ft.faq_id='.db_input($this->id).') '
                .' LEFT JOIN '.COLLECTION_TABLE.' pt ON(pt.collection_id=t.collection_pid) '
                .' ORDER BY t.collection';
            if (($res=db_query($sql)) && db_num_rows($res)) {
                while(list($id,$name,$color) = db_fetch_row($res))
                    $this->collections[$id]=array(
                    'name' => $name,
                    'color'=> $color);
            }
        }

        return $this->collections;
    }
    
    
    

    /* ------------------> Setter methods <--------------------- */
    function setPublished($val) { $this->ht['ispublished'] = !!$val; }
    function setQuestion($question) { $this->ht['question'] = Format::striptags(trim($question)); }
    function setAnswer($text) { $this->ht['answer'] = $text; }
    function setKeywords($words) { $this->ht['keywords'] = $words; }
    function setNotes($text) { $this->ht['notes'] = $text; }

    /* For ->attach() and ->detach(), use $this->attachments() (nolint) */
    function attach($file) { return $this->_attachments->add($file); }
    function detach($file) { return $this->_attachments->remove($file); }

    function publish() {
        $this->setPublished(1);

        return $this->apply();
    }

    function unpublish() {
        $this->setPublished(0);

        return $this->apply();
    }

    /* Same as update - but mainly called after one or more setters are changed. */
    function apply() {
        $errors = array();
        //XXX: set errors and add ->getErrors() & ->getError()
        return $this->update($this->ht, $errors);
    }

    function updateCollections($ids){

        if($ids) {
            $collections = $this->getCollectionsIds();
            foreach($ids as $id) {
                if($collections && in_array($id,$collections)) continue;
                $sql='INSERT IGNORE INTO '.FAQ_COLLECTION_TABLE
                    .' SET faq_id='.db_input($this->getId())
                    .', collection_id='.db_input($id);
                db_query($sql);
            }
        }

        $sql='DELETE FROM '.FAQ_COLLECTION_TABLE.' WHERE faq_id='.db_input($this->getId());
        if($ids)
            $sql.=' AND collection_id NOT IN('.implode(',', db_input($ids)).')';

        db_query($sql);

        return true;
    }

    function update($vars, &$errors) {

        if(!$this->save($this->getId(), $vars, $errors))
            return false;

        $this->updateCollections($vars['collections']);

        //Delete removed attachments.
        $keepers = $vars['files']?$vars['files']:array();
        if(($attachments = $this->attachments->getSeparates())) {
            foreach($attachments as $file) {
                if($file['id'] && !in_array($file['id'], $keepers))
                    $this->attachments->delete($file['id']);
            }
        }

        //Upload new attachments IF any.
        if($_FILES['attachments'] && ($files=AttachmentFile::format($_FILES['attachments'])))
            $this->attachments->upload($files);

        // Inline images (attached to the draft)
        $this->attachments->deleteInlines();
        if (isset($vars['draft_id']) && $vars['draft_id'])
            if ($draft = Draft::lookup($vars['draft_id']))
                $this->attachments->upload($draft->getAttachmentIds(), true);

        $this->reload();

        return true;
    }

    function getAttachmentsLinks($separator=' ',$target='') {

        $str='';
        if(($attachments=$this->attachments->getSeparates())) {
            foreach($attachments as $attachment ) {
            /* The h key must match validation in file.php */
            $hash=$attachment['key'].md5($attachment['id'].session_id().strtolower($attachment['key']));
            if($attachment['size'])
                $size=sprintf('&nbsp;<small>(<i>%s</i>)</small>',Format::file_size($attachment['size']));

            $str.=sprintf('<a class="Icon file" href="file.php?h=%s" target="%s">%s</a>%s&nbsp;%s',
                    $hash, $target, Format::htmlchars($attachment['name']), $size, $separator);

            }
        }
        return $str;
    }

    function delete() {

        $sql='DELETE FROM '.FAQ_TABLE
            .' WHERE faq_id='.db_input($this->getId())
            .' LIMIT 1';
        if(!db_query($sql) || !db_affected_rows())
            return false;

        //Cleanup collections.
        db_query('DELETE FROM '.FAQ_COLLECTION_TABLE.' WHERE faq_id='.db_input($this->id));
        //Cleanup attachments.
        $this->attachments->deleteAll();

        return true;
    }

    /* ------------------> Static methods <--------------------- */

    function add($vars, &$errors) {
        if(!($id=self::create($vars, $errors)))
            return false;

        if(($faq=self::lookup($id))) {
            $faq->updateCollections($vars['collections']);

            if($_FILES['attachments'] && ($files=AttachmentFile::format($_FILES['attachments'])))
                $faq->attachments->upload($files);

            // Inline images (attached to the draft)
            if (isset($vars['draft_id']) && $vars['draft_id'])
                if ($draft = Draft::lookup($vars['draft_id']))
                    $faq->attachments->upload($draft->getAttachmentIds(), true);

            $faq->reload();
        }

        return $faq;
    }

    function create($vars, &$errors) {
        return self::save(0, $vars, $errors);
    }

    function lookup($id) {
        return ($id && is_numeric($id) && ($obj= new FAQ($id)) && $obj->getId()==$id)? $obj : null;
    }

    function countPublishedFAQs() {
        $sql='SELECT count(faq.faq_id) '
            .' FROM '.FAQ_TABLE.' faq '
            .' INNER JOIN '.FAQ_CATEGORY_TABLE.' cat ON(cat.category_id=faq.category_id AND cat.ispublic=1) '
            .' WHERE faq.ispublished=1';

        return db_result(db_query($sql));
    }

    function findIdByQuestion($question) {
        $sql='SELECT faq_id FROM '.FAQ_TABLE
            .' WHERE question='.db_input($question);

        list($id) =db_fetch_row(db_query($sql));

        return $id;
    }

    function findByQuestion($question) {

        if(($id=self::findIdByQuestion($question)))
            return self::lookup($id);

        return false;
    }

    function save($id, $vars, &$errors, $validation=false) {

        //Cleanup.
        $vars['question']=Format::striptags(trim($vars['question']));

        //validate
        if($id && $id!=$vars['id'])
            $errors['err'] = 'Internal error. Try again';

        if(!$vars['question'])
            $errors['question'] = 'Question required';
        elseif(($qid=self::findIdByQuestion($vars['question'])) && $qid!=$id)
            $errors['question'] = 'Question already exists';

        if(!$vars['category_id'] || !($category=Category::lookup($vars['category_id'])))
            $errors['category_id'] = 'Category is required';

        if(!$vars['answer'])
            $errors['answer'] = 'FAQ answer is required';

        if($errors || $validation) return (!$errors);

        //save
        $sql=' updated=NOW() '
            .', question='.db_input($vars['question'])
            .', answer='.db_input(Format::sanitize($vars['answer'], false))
            .', category_id='.db_input($vars['category_id'])
            .', ispublished='.db_input(isset($vars['ispublished'])?$vars['ispublished']:0)
            .', notes='.db_input(Format::sanitize($vars['notes']));

        if($id) {
            $sql='UPDATE '.FAQ_TABLE.' SET '.$sql.' WHERE faq_id='.db_input($id);
            if(db_query($sql))
                return true;

            $errors['err']='Unable to update FAQ.';

        } else {
            $sql='INSERT INTO '.FAQ_TABLE.' SET '.$sql.',created=NOW()';
            if(db_query($sql) && ($id=db_insert_id()))
                return $id;

            $errors['err']='Unable to create FAQ. Internal error';
        }

        return false;
    }
}
?>
