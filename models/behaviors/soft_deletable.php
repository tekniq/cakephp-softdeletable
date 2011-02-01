<?php
/**
 * @todo Support HasAndBelongsToMany relationships.
 * @todo Support more column types than boolean and datetime.
 * @todo Implement restoreAll() for exclusive associations.
 * @todo Add model association option to not filter deleted records.
 */
class SoftDeletableBehavior extends ModelBehavior {
	var $field = 'deleted';
	var $settings = array();
/**
 * Setup method for behavior instatiation - merge model settings.
 * 
 * @param object $model Model instance
 * @param array $settings Settings passed in from model
 */	
	function setup(&$model) {
		$this->enableDeletable($model);
		
		# Set associated model conditions
		foreach (array('hasOne', 'hasMany', 'belongsTo') as $type) {
			foreach ($model->$type as $alias => &$params) {
				if (!isset($model->$alias)) {
					continue;
				}
				
				$association =& $model->$alias;
				if ($this->_isDeletable($association)) {
					$params['conditions'][$association->alias . '.' . $this->field] = $this->_notDeleted($association);
				}
			}
		}
	}
/**
 * When a record is deleted, set 'deleted' field to true and cancel hard delete. If a
 *  record has already been soft deleted, continue with the hard delete.
 * 
 * @param object $model Model instance
 * @param boolean $cascade True if model cascades deletes
 * @return boolean True if 'deleted' field doesn't exist (continue with hard delete)
 */	
	function beforeDelete(&$model, $cascade) {
		if ($model->hasField($this->field)) {
			$_deleted = $model->find('count', array(
				'isDeleted' => true,
				'conditions' => array($model->escapeField($model->primaryKey) => $model->id)
			));
			
			if (!$this->settings[$model->name]['enabled'] || $_deleted) {
				return true;
			}
			
			if ($this->_update($model, $this->_deleted($model)) && $cascade) {
				$model->_deleteDependent($model->id, $cascade);
			}
			$model->afterDelete();		
			return false;
		}		
		return true;
	}
/**
 * Filter out deleted records when searching with Model::find().
 * 
 * @param object $model Model instance
 * @param boolean $queryData Information about current query: conditions, fields, etc.
 * @return boolean True if 'deleted' field doesn't exist (continue with hard delete)
 */		
	function beforeFind($model, $queryData) {
		$queryData = array_merge(array('isDeleted' => false), $queryData);

		if ($this->settings[$model->name]['enabled'] && $model->hasField($this->field) && isset($queryData['isDeleted'])) {
			if ($queryData['isDeleted']) {
				$queryData['conditions']['NOT'][$model->alias . '.' . $this->field] = $this->_notDeleted($model);
			} else {
				$queryData['conditions'][$model->alias . '.' . $this->field] = $this->_notDeleted($model);
			}
		}
		return $queryData;
	}
/**
 * Disables soft deletable behavior for a specific model.
 * 
 * @param object $model Model instance
 * @return void
 */
	function disableDeletable(&$model) {
		$this->settings[$model->name]['enabled'] = false;
	}
/**
 * Enables soft deletable behavior for a specific model.
 * 
 * @param object $model Model instance
 * @return void
 */
	function enableDeletable(&$model) {
		$this->settings[$model->name]['enabled'] = true;
	}
/**
 * Restore a previously soft deleted record.
 * 
 * @param object $model Model instance
 * @param numeric $id Id of record to restore
 * @return boolean True if restore was successful, false if otherwise
 */
	function restore(&$model, $id = null, $cascade = true) {
		if (is_numeric($id)) {
			$model->id = $id;
		}
		if (!$model->id) {
			return false;
		}
		if ($this->settings[$model->name]['enabled']) {
			$model->disableDeletable();
			$enable = true;
		}
		if ($model->exists() && $model->beforeRestore()) {
			$threshold = $model->field($this->field);
			if ($this->_update($model, $this->_notDeleted($model))) {
				$model->afterRestore();
				if (!empty($enable)) {
					$model->enableDeletable();
				}
				if ($cascade) {
					$this->_restoreDependent($model, $id, $cascade, $threshold);
				}
				return true;
			}
		}
		if (!empty($enable)) {
			$model->enableDeletable();
		}
		return false;
	}
/**
 * Restore callbacks to be overridden by models.
 * 
 */	
	function beforeRestore() {
		return true;
	}
	function afterRestore() {
	}
/**
 * Cascades restore on dependent models with a datetime deleted field and records
 *  that were deleted on or after the parent record.
 *  
 *
 * @param string $id ID of record that was deleted
 * @param boolean $cascade Set to true to delete records that depend on this record
 * @return void
 * @access protected
 */
	function _restoreDependent($model, $id, $cascade, $threshold) {
		if (!empty($model->__backAssociation)) {
			$savedAssociatons = $model->__backAssociation;
			$model->__backAssociation = array();
		}
		foreach (array_merge($model->hasMany, $model->hasOne) as $alias => $params) {
			if ($params['dependent'] === true && $cascade === true) {
				$association =& $model->$alias;
				if ($this->_isDeletable($association) && $association->_schema[$this->field]['type'] != 'datetime') {
					continue;
				}
				$conditions = array(
					$association->escapeField($params['foreignKey']) => $id,
					$association->escapeField($this->field) . ' >=' => $threshold
				);
				if ($params['conditions']) {
					$conditions = array_merge((array)$params['conditions'], $conditions);
				}
				if (array_key_exists($association->alias . '.' . $this->field, $conditions)) {
					unset($conditions[$association->alias . '.' . $this->field]);
				}
				
				$association->recursive = -1;
				$records = $association->find('all', array(
					'isDeleted' => null, 
					'conditions' => $conditions, 
					'fields' => $association->primaryKey
				));
				
				if (!empty($records)) {
					foreach ($records as $record) {
						$association->restore($record[$association->alias][$association->primaryKey]);
					}
				}
			}
		}
		if (isset($savedAssociatons)) {
			$model->__backAssociation = $savedAssociatons;
		}
	}
/**
 * Evaluate if the model setup for the soft deletable behavior.
 * 
 * @param object $model Model instance
 * @return boolean
 * @access protected
 */	
	function _isDeletable(&$model) {
		return in_array('SoftDeletable.SoftDeletable', (array)$model->actsAs) && $model->hasField($this->field);
	}
/**
 * Determine the value to set in the deleted field to based on column type in schema.
 * 
 * @param object $model Model instance
 * @return mixed
 * @access protected
 */	
	function _deleted(&$model) {
		if ($model->_schema[$this->field]['type'] == 'datetime') {
			return date('c');
		}
		return true;
	}	
	function _notDeleted(&$model) {
		if ($model->_schema[$this->field]['type'] == 'datetime') {
			return null;
		}
		return false;
	}
/**
 * Update the soft deletable field with a new value.
 * 
 * @param object $model Model instance
 * @param numeric $value New value for field
 * @access protected
 */	
	function _update(&$model, $value) {
		return $model->save(
			array($model->alias => array($this->field => $value)), 
			array('validate' => false, 'callbacks' => true)
		);
	}	
}