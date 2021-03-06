<?php

namespace app\models\search;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Letter;

/**
 * LetterSearch represents the model behind the search form of `app\models\Letter`.
 */
class LetterSearch extends Letter
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['id', 'status', 'material_id'], 'integer'],
            [['created_at', 'participant_id', 'conference_id', 'user_id', 'application_id'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = Letter::find()->joinWith(['participant'])->joinWith(['user']);

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => [
                'defaultOrder' => ['status' => SORT_ASC],
                'attributes' => [
                    'created_at',
                    'status',
                    'participant_id' => [
                        'asc' => ['{{%participant}}.name' => SORT_ASC],
                        'desc' => ['{{%participant}}.name' => SORT_DESC],
                    ],
                    'material_id',
                    'conference_id',
                    'user_id' => [
                        'asc' => ['{{%user}}.username' => SORT_ASC],
                        'desc' => ['{{%user}}.username' => SORT_DESC],
                    ],
                ],
            ],
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'created_at' => $this->created_at,
            'status' => $this->status,
            'material_id' => $this->material_id,
        ]);

        $query->andFilterWhere(['like', 'participant.email', $this->participant_id])
                ->andFilterWhere(['like', 'conference_id', $this->conference_id])
                ->andFilterWhere(['like', 'application_id', $this->application_id])
                ->andFilterWhere(['like', 'user.username', $this->user_id]);

        return $dataProvider;
    }
}
