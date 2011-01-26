<?php
/**
 * @todo Support HasAndBelongsToMany relationships.
 * @todo Support more column types than boolean and datetime.
 * @todo Support cascading restores.
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
			foreach ($model->$type as $modelName => &$params) {
				if (!isset($model->$modelName)) {
					continue;
				}
				
				$association = $model->$modelName;
				if (in_array('SoftDeletable', (array)$association->actsAs) && $association->hasField($this->field)) {
					$params['conditions'][$modelName . '.' . $this->field] = $this->notDeleted($association);
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
			if (!$this->settings[$model->name]['enabled'] || $model->field($this->field) != $this->notDeleted($model)) {
				return true;
			}
			
			if ($this->update($model, $this->deleted($model)) && $cascade) {
				$model->_deleteDependent($model->id, $cascade);
				$model->_deleteLinks($model->id);
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
			if ($queryData['isDeleted'] === true) {
				$queryData['conditions']['NOT'][$model->alias . '.' . $this->field] = $this->notDeleted($model);
			} else($queryData['isDeleted'] === false) {
				$queryData['conditions'][$model->alias . '.' . $this->field] = $this->notDeleted($model);
			}
		}
		return $queryData;
	}
/**
 * Disables soft deletable behavior for a specific model.
 * 
 * @param object $model Model instance
 * @return null
 */
	function disableDeletable(&$model) {
		$this->settings[$model->name]['enabled'] = false;
	}
/**
 * Enables soft deletable behavior for a specific model.
 * 
 * @param object $model Model instance
 * @return null
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
	function restore(&$model, $id = null) {
		if (is_numeric($id)) {
			$model->id = $id;
		}
		if (!$model->id) {
			return false;
		}
		$model->disableDeletable();
		if ($model->exists() && $model->beforeRestore()) {
			if ($this->update($model, $this->notDeleted($model))) {
				$model->afterRestore();
				$model->enableDeletable();
				return true;
			}
		}		
		$model->enableDeletable();
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
 * Determine the value to set in the deleted field to based on column type in schema.
 * 
 * @param object $model Model instance
 * @return mixed
 * @access private
 */	
	private function deleted($model) {
		if ($model->_schema[$this->field]['type'] == 'datetime') {
			return date('c');
		}
		return true;
	}	
	private function notDeleted($model) {
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
 * @access private
 */	
	private function update(&$model, $value) {
		return $model->save(
			array($model->alias => array($this->field => $value)), 
			array('validate' => false, 'callbacks' => true)
		);
	}
}