<?php

class Sidane_Threadmarks_DataWriter_Threadmark extends XenForo_DataWriter
{
  const DATA_THREAD = 'threadInfo';

  protected function _getFields()
  {
    return array(
      'threadmarks' => array(
        'threadmark_id' => array(
          'type'          => self::TYPE_UINT,
          'autoIncrement' => true
        ),
        'threadmark_category_id' => array(
          'type'         => self::TYPE_UINT,
          'required'     => true,
          'verification' => array('$this', '_verifyThreadmarkCategoryId')
        ),
        'user_id' => array(
          'type'     => self::TYPE_UINT,
          'required' => true
        ),
        'threadmark_date' => array(
          'type'     => self::TYPE_UINT,
          'required' => true,
          'default'  => XenForo_Application::$time
        ),
        'thread_id' => array(
          'type'     => self::TYPE_UINT,
          'required' => true
        ),
        'post_id' => array(
          'type'     => self::TYPE_UINT,
          'required' => true
        ),
        'label' => array(
          'type'          => self::TYPE_STRING,
          'required'      => true,
          'maxLength'     => 255,
          'requiredError' => 'please_enter_label_for_threadmark'
        ),
        'message_state' => array(
          'type'          => self::TYPE_STRING,
          'default'       => 'visible',
          'allowedValues' => array('visible', 'moderated', 'deleted')
        ),
        'last_edit_date' => array(
          'type'    => self::TYPE_UINT,
          'default' => 0
        ),
        'last_edit_user_id' => array(
          'type'    => self::TYPE_UINT,
          'default' => 0
        ),
        'edit_count' => array(
          'type'    => self::TYPE_UINT_FORCED,
          'default' => 0
        ),
        'position' => array(
          'type' => self::TYPE_UINT_FORCED
        ),
        'parent_threadmark_id' => array(
          'type'    => self::TYPE_UINT_FORCED,
          'default' => 0
        ),
        'depth' => array(
          'type'    => self::TYPE_UINT_FORCED,
          'default' => 0
        )
      )
    );
  }

  protected function _getExistingData($data)
  {
    if (!$threadmark_id = $this->_getExistingPrimaryKey($data, 'threadmark_id'))
    {
      return false;
    }

    return array('threadmarks' => $this->_getThreadmarksModel()->getThreadMarkById($threadmark_id));
  }

  protected function _getUpdateCondition($tableName)
  {
    return 'threadmark_id = ' . $this->_db->quote($this->getExisting('threadmark_id'));
  }

  protected function _preSave()
  {
    parent::_preSave();

    if ($this->isInsert() || $this->isChanged('threadmark_category_id'))
    {
      $threadmarkCategoryPositions = $this
        ->_getThreadmarksModel()
        ->getThreadmarkCategoryPositionsByThread($this->_getThreadData());
      $threadmarkCategoryId = $this->get('threadmark_category_id');

      $lastCategoryPosition = 0;
      if (isset($threadmarkCategoryPositions[$threadmarkCategoryId]))
      {
        $lastCategoryPosition = $threadmarkCategoryPositions[$threadmarkCategoryId];
      }

      $maxPosition = $lastCategoryPosition + 1;
      $currentPosition = $this->get('position');

      if ($currentPosition > $maxPosition)
      {
        $this->set('position', $maxPosition);
      }
    }

    // ensure the positional information is valid
    if ($this->isUpdate() && $this->isChanged('threadmark_category_id'))
    {
      $this->set('depth', 0);
      $this->set('parent_threadmark_id', 0);
    }

    if ($this->isUpdate() && $this->isChanged('label'))
    {
      $this->set('last_edit_date', XenForo_Application::$time);
      $this->set('last_edit_user_id', XenForo_Visitor::getUserId());
      $this->set('edit_count', $this->get('edit_count') + 1);
    }
  }

  protected function _postSave()
  {
    if ($this->isUpdate())
    {
      if ($this->isChanged('label'))
      {
        $this->_insertEditHistory();
      }
    }

    if (
      $this->isInsert() ||
      $this->isChanged('message_state') ||
      $this->isChanged('threadmark_category_id')
    )
    {
      $this->_updateThreadmarkPositions();
    }

    parent::_postSave();
  }

  protected function _postSaveAfterTransaction()
  {
    parent::_postSaveAfterTransaction();

    $this->_indexForSearch();
    $this->_publishAndNotify();
  }

  protected function _postDelete()
  {
    parent::_postDelete();

    $this->_getEditHistoryModel()->deleteEditHistoryForContent(
      $this->getContentType(), $this->getContentId()
    );

    $this->_updateThreadmarkPositions(true);
    $this->_deleteFromSearchIndex();
    $this->_deleteFromNewsFeed();
  }

  protected function getContentType()
  {
    return 'threadmark';
  }

  protected function getContentId()
  {
    return $this->get('post_id');
  }

  protected function _getThreadData()
  {
    if (!$thread = $this->getExtraData(self::DATA_THREAD))
    {
      $thread = $this->_getThreadModel()->getThreadById($this->get('thread_id'));
      $this->setExtraData(self::DATA_THREAD, $thread);
    }

    return $thread;
  }

  protected function _indexForSearch()
  {
    if ($this->get('message_state') == 'visible')
    {
      if ($this->getExisting('message_state') != 'visible' || $this->isChanged('message') || $this->isChanged('threadmark_category_id'))
      {
        $this->_insertOrUpdateSearchIndex();
      }
    }
    else if ($this->isUpdate() && $this->get('message_state') != 'visible' && $this->getExisting('message_state') == 'visible')
    {
      $this->_deleteFromSearchIndex();
    }
  }

  protected function _insertOrUpdateSearchIndex()
  {
    $dataHandler = $this->_getSearchDataHandler();
    if (!$dataHandler)
    {
      return;
    }

    $thread = $this->_getThreadData();

    $indexer = new XenForo_Search_Indexer();
    $dataHandler->insertIntoIndex($indexer, $this->getMergedData(), $thread);
  }

  protected function _deleteFromSearchIndex()
  {
    $dataHandler = $this->_getSearchDataHandler();
    if (!$dataHandler)
    {
      return;
    }

    $indexer = new XenForo_Search_Indexer();
    $dataHandler->deleteFromIndex($indexer, $this->getMergedData());
  }

  protected function _publishAndNotify()
  {
    if ($this->isInsert())
    {
      $this->_publishToNewsFeed();
    }
  }

  protected function _publishToNewsFeed()
  {
    $this->_getNewsFeedModel()->publish(
      $this->get('user_id'),
      $this->get('username'),
      $this->getContentType(),
      $this->getContentId(),
      ($this->isUpdate() ? 'update' : 'insert')
    );
  }

  protected function _deleteFromNewsFeed()
  {
    $this->_getNewsFeedModel()->delete($this->getContentType(), $this->getContentId());
  }

  protected function _updateThreadmarkPositions($isDelete = false)
  {
    $updateThreadData = false;

    if ($this->isInsert())
    {
      $updateThreadData = true;
    }

    if (
      ($this->getExisting('message_state') == 'visible') &&
      (
        (($this->get('message_state') != 'visible') || $isDelete) ||
        $this->isChanged('threadmark_category_id')
      )
    )
    {
      // message has become invisible or threadmark has changed categories
      $this->_db->query(
        "UPDATE threadmarks
          SET position = GREATEST(CAST(position AS SIGNED) - 1, 0)
          WHERE thread_id = ?
            AND threadmark_category_id = ?
            AND post_id <> ?
            AND message_state = 'visible'
            AND position >= ?",
        array(
          $this->getExisting('thread_id'),
          $this->getExisting('threadmark_category_id'),
          $this->getExisting('post_id'),
          $this->getExisting('position')
        )
      );

      $updateThreadData = true;
    }

    if (
      ($this->get('message_state') == 'visible') &&
      (
        ($this->getExisting('message_state') != 'visible') ||
        $this->isChanged('threadmark_category_id')
      )
    )
    {
      // message has become visible or threadmark has changed categories
      $this->_db->query(
        "UPDATE threadmarks
          SET position = position + 1
          WHERE thread_id = ?
            AND threadmark_category_id = ?
            AND post_id <> ?
            AND message_state = 'visible'
            AND position >= ?",
        array(
          $this->get('thread_id'),
          $this->get('threadmark_category_id'),
          $this->get('post_id'),
          $this->get('position')
        )
      );

      $updateThreadData = true;
    }

    if ($updateThreadData)
    {
      $this->_getThreadmarksModel()->updateThreadmarkDataForThread(
        $this->get('thread_id')
      );
    }
  }

  protected function _insertEditHistory()
  {
    $historyDw = XenForo_DataWriter::create('XenForo_DataWriter_EditHistory', XenForo_DataWriter::ERROR_SILENT);
    $historyDw->bulkSet(array(
        'content_type' => $this->getContentType(),
        'content_id' => $this->getContentId(),
        'edit_user_id' => XenForo_Visitor::getUserId(),
        'old_text' => $this->getExisting('label')
    ));
    $historyDw->save();
  }

  protected function _verifyThreadmarkCategoryId($threadmarkCategoryId)
  {
    if (empty($threadmarkCategoryId))
    {
      return false;
    }

    $threadmarkCategory = $this
      ->_getThreadmarksModel()
      ->getThreadmarkCategoryById($threadmarkCategoryId);

    if (!empty($threadmarkCategory))
    {
      return true;
    }

    $this->error(
      new XenForo_Phrase('sidane_please_enter_valid_threadmark_category_id'),
      'threadmark_category_id'
    );

    return false;
  }

  /**
   * @return XenForo_Search_DataHandler_Abstract|Sidane_Threadmarks_Search_DataHandler_Threadmark
   */
  protected function _getSearchDataHandler()
  {
    return XenForo_Search_DataHandler_Abstract::create('Sidane_Threadmarks_Search_DataHandler_Threadmark');
  }

  /**
   * @return XenForo_Model|XenForo_Model_EditHistory
   */
  protected function _getEditHistoryModel()
  {
    return $this->getModelFromCache('XenForo_Model_EditHistory');
  }

  /**
   * @return XenForo_Model|XenForo_Model_Thread|Sidane_Threadmarks_XenForo_Model_Thread
   */
  protected function _getThreadModel()
  {
    return $this->getModelFromCache('XenForo_Model_Thread');
  }

  /**
   * @return XenForo_Model|Sidane_Threadmarks_Model_Threadmarks
   */
  protected function _getThreadmarksModel()
  {
    return $this->getModelFromCache('Sidane_Threadmarks_Model_Threadmarks');
  }

  /**
   * @return XenForo_Model|XenForo_Model_NewsFeed
   */
  protected function _getNewsFeedModel()
  {
    return $this->getModelFromCache('XenForo_Model_NewsFeed');
  }
}
