<?php

namespace app\models\search;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Application;

/**
 * ApplicationSearch represents the model behind the search form of `app\models\Application`.
 */
class ApplicationSearch extends Application
{
    public $user_id;
    public $username;
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['id', 'material_id', 'status',], 'integer'],
            [['created_at', 'user_id', 'username', 'participant_id', 'category_id', 'conference_id'], 'safe'],
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
     * @param null $user_id
     * @return ActiveDataProvider
     */
    public function search($params, $user_id = null)
    {
        $this->user_id = $user_id;

        $query = Application::find()->joinWith(['participant'])->joinWith(['conference'])->joinWith(['category'])->joinWith(['user']);

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
                    'category_id',
                    'conference_id',
                    'username' => [
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
            'material_id' => $this->material_id,
            'status' => $this->status,
        ]);

        if ($this->user_id) {
            $query->andWhere([
                'user_id' => $this->user_id,
            ]);
        }

        $query->andFilterWhere(['like', 'participant.email', $this->participant_id])
                ->andFilterWhere(['like', 'category_id', $this->category_id])
                ->andFilterWhere(['like', 'conference_id', $this->conference_id])
                ->andFilterWhere(['like', 'user.username', $this->username]);

        return $dataProvider;
    }
}
