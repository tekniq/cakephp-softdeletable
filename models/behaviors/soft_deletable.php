<?php
/**
 * @todo Support HasAndBelongsToMany relationships.
 */
class SoftDeletableBehavior extends ModelBehavior {
	var $field = 'deleted';
/**
 * Setup method for behavior instatiation - merge model settings.
 * 
 * @param object $Model Model instance
 * @param array $settings Settings passed in from model
 */	
	function setup(&$Model) {	
		# Set associated model conditions
		foreach (array('hasOne', 'hasMany', 'belongsTo') as $type) {
			foreach ($Model->$type as $modelName => &$params) {	
				$Association = $Model->$modelName;
				
				if (in_array('SoftDeletable', (array)$Association->actsAs) && $Association->hasField($this->field)) {
					$params['conditions'][$modelName . '.' . $this->field] = '0';
				}
			}
		}
	}
/**
 * When a record is deleted, set 'deleted' field to true and cancel hard delete.
 * 
 * @param object $Model Model instance
 * @param boolean $cascade True if model cascades deletes
 * @return boolean True if 'deleted' field doesn't exist (continue with hard delete)
 */	
	function beforeDelete(&$Model, $cascade) {
		if ($Model->hasField($this->field)) {
			$id = $Model->id;	
			
			if ($Model->save(array($Model->alias => array($this->field => 'true')), array('validate' => false, 'callbacks' => false)) && $cascade) {
				$Model->_deleteDependent($id, $cascade);
				$Model->_deleteLinks($id);
			}
			$Model->afterDelete();		
			return false;
		}		
		return true;
	}
/**
 * Filter out deleted records when searching with Model::find().
 * 
 * @param object $Model Model instance
 * @param boolean $queryData Information about current query: conditions, fields, etc.
 * @return boolean True if 'deleted' field doesn't exist (continue with hard delete)
 */		
	function beforeFind($Model, $queryData) {
		if ($Model->hasField($this->field)) {
			$queryData['conditions'][$Model->alias . '.' . $this->field] = '0';
		}
		return $queryData;
	}
}
?>