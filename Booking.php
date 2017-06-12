<?php

namespace app\models\booking;

use app\helpers\TimestampHelper;
use app\models\booking\enums\BookingStatusEnum;
use app\models\booking\queries\BookingQuery;
use app\models\calendar\CalendarPeriod;
use app\models\calendar\PeriodTypeEnum;
use app\models\calendar\periodValidators\PeriodsConflictsValidator;
use app\models\calendar\periodValidators\PeriodsExistsValidator;
use app\models\guest\Guest;
use app\models\room\Room;
use app\services\UsersServices;
use Yii;
use yii\base\Exception;
use yii\db\Query;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "{{%booking}}".
 * @property integer          $id
 * @property string           $notes
 * @property integer          $adjustment
 * @property integer          $adjustment_tax
 * @property integer          $tax
 * @property string           $adjustment_description
 * @property integer          $status
 * @property string           $statusTitle
 * @property integer          $room_price
 * @property integer          $room_extras
 * @property integer          $pets_price
 * @property string           $booking_pets_type
 * @property string           $booking_pets_count
 * @property integer          $add_credit
 * @property integer          $payment_reminder
 * @property integer          $arrival_reminder
 * @property integer          $thanks_for_staying
 * @property integer          $time_save
 * @property integer          $status_or_source
 * @property array            $rooms
 * @property BookingRoom[]    $bookingRooms
 * @property BookingPayment[]    $bookingPayments
 * @property Room[]           $roomsModel
 * @property Guest[]          $guests
 * @property int              $user_id
 * @property BookingRoom[]    $roomsArray
 * @property CalendarPeriod[] $periodsArray
 * @property array            $guestsArray
 */
class Booking extends \yii\db\ActiveRecord
{
    // tomake Guests save only ids (now array)
    public $roomsArray;
    public $periodsArray;
    public $guestsArray;
    public $guestsId;

    public static function getAvailableRoomsMap()
    {
        $rooms = Room::find()->all();
        return ArrayHelper::map($rooms, 'id', 'title');
    }
    
    public function findGuests()
    {
        $ids = array_map(function ($guestIdArr) {
            return $guestIdArr['guest_id'];
        }, $this->guests);
        
        return Guest::findAll(['id' => $ids]);
    }
    
    public function getTotalPrice()
    {
        $totalPrice = $this->getSubtotalPrice();
        $session = Yii::$app->session;
        $taxable_sum = $session->get('not_taxable');
        // Start Taxable For Room
        if ($session->has('taxable_for_room')){
            $session->remove('taxable_for_room');
            $this->tax = 0;
        }
        // End Taxable For Room
        if($taxable_sum){
            $totalPrice = round($totalPrice - $taxable_sum);
        }
        $session->remove('not_taxable');
        if ($this->tax) {
            $totalPrice = $totalPrice + ($totalPrice * $this->tax / 100);
            //$totalPrice = $totalPrice + $this->tax;
        }
        if($taxable_sum){
            $totalPrice = round($totalPrice + $taxable_sum);
        }
        return $totalPrice;
    }
    
    public function getSubtotalPrice()
    {
        if(!$this->pets_price){
            $this->pets_price = 0;
        }
        return $this->room_price + $this->room_extras + $this->adjustment + $this->adjustment_rooms + $this->adjustment_extras + $this->adjustment_pets  + $this->pets_price;
    }
    
    
    public function getFirstGuestName()
    {
        
        if ($this->guests) {
            return Guest::findOne($this->guests[0]['guest_id'])->full_name;
        } else {
            return '';
        }
    }
    
    public function afterFind()
    {
        // toref Переписать это
        if (!is_array($this->guests)) {
            $this->guests = json_decode($this->guests, TRUE);
        }
        parent::afterFind(); // TODO: Change the autogenerated stub
    }
    
    public function beforeSave($insert)
    {
        //$this->user_id = \Yii::$app->user->id;
        if(Yii::$app->user->identity->role == 50 || Yii::$app->user->identity->role == 40){
            $this->user_id = UsersServices::getUsersProperty();
        } elseif(Yii::$app->user->identity->role == 30){
            $this->user_id = UsersServices::getUsersBookkeeper();
        } elseif(Yii::$app->user->identity->role == 29){
            $this->user_id = UsersServices::getUsersReservationist();
        }
        $this->guests = json_encode($this->guests);
        $this->time_save = TimestampHelper::timeToDaysDashboard(time());
        if(!$this->status_or_source){
            $this->status_or_source = BookingStatusOrSourceEnum::OWNER_BLOCK;
        }
        return parent::beforeSave($insert); // TODO: Change the autogenerated stub
    }
    
    public function beforeDelete()
    {
        CalendarPeriod::deleteAll(['item_type' => PeriodTypeEnum::BOOKING_ROOM, 'item_id' => $this->id]);
        return parent::beforeDelete(); // TODO: Change the autogenerated stub
    }
    
    
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes); // TODO: Change the autogenerated stub
        $this->saveRooms();
    }
    
    private function saveRooms()
    {
        $rooms = $this->roomsArray;
        if ($rooms) {
            BookingRoom::deleteAll(['booking_id' => $this->id]);
            foreach ($rooms as $room) {
                $periodRecord = $room->periodTmp;
                $periodRecord->item_root_id = $this->id;
                $periodRecord->save();
                
                $room->booking_id = $this->id;
                $room->period_id = $periodRecord->id;
                $room->save();
            }
        }
    }
    
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%booking}}';
    }
    
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['status',  'user_id'], 'integer'],
            ['guests', 'required'],
            [['tax','room_price','room_extras'],'double'],
            [['notes'], 'string', 'max' => 500],
            [['adjustment','adjustment_rooms','adjustment_extras','adjustment_pets'], 'double'],
            [['adjustment_description'], 'string', 'max' => 255],
            [['rooms'], function ($attribute) {
                $rooms = $this->$attribute;
                foreach ($rooms as $room) {
                  //  var_dump($room['guests_count']); // Count in booking select user
                 //   var_dump($room['room_id']); // Count in booking select user
                    if (!$room['room_id']) {
                        $this->addError($attribute, 'You must specify room for booking');
                    }
                    if($room['room_id']){
                        $roomModel = (new Query())->select(['max_occupancy'])->from('vr_room')->where(['id' => $room['room_id']])->one();
                        //var_dump($roomModel);
                        if($roomModel['max_occupancy'] <= $room['guests_count']){
                            $this->addError($attribute, 'More than can accommodate a room');
                        }
                    }
                    if (!$room['guests_count']) {
                        $this->addError($attribute, 'You must specify count of guests for booking room');
                    }
                }
            }],
            [['periods'], PeriodsExistsValidator::className()],
            [['periods'], PeriodsConflictsValidator::className(), 'checkTypes' => [
                PeriodTypeEnum::BOOKING_BLOCK,
                PeriodTypeEnum::BOOKING_ROOM,
            ],],
            /*[['guests'], function ($attribute) {
                $guests = $this->$attribute;
                foreach ($guests as $guest) {
                    if (!$guest['guest_id']) {
                        $this->addError($attribute, 'You must specify guest for booking');
                    }
                }
            }],*/
            [['guests'], function ($attribute) {
                $guests[]['guest_id'] = $this->$attribute;
                foreach ($guests as $guest) {
                    if (!$guest['guest_id']) {
                        $this->addError($attribute, 'You must specify guest for booking');
                    }
                }
            }],
            [['payment_reminder', 'arrival_reminder', 'thanks_for_staying', 'time_save', 'status_or_source', 'add_credit', 'adjustment_tax', 'pets_price', 'booking_pets_type', 'booking_pets_count'], 'safe'],
        ];
    }
    
    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id'                  => 'ID',
            'notes'               => 'Notes',
            'adjustment'          => 'Adjustment',
            //'tax'                 => 'Tax',
            'tax'                 => 'Tax (%)',
            'pets_price'           => 'Pets price',
            'firstGuestName'      => 'Guest name',
            'currentStayForLabel' => 'Stay for',
            'adjustment_tax'      => 'Tax adjustment',
            /*'full_amount_payment' => 'Amount',
            'type_of_payment'     => 'type',
            'notes_entered'       => 'notes',*/
        ];
    }
    
    public function beforeValidate()
    {
        $this->fixMoneyValues();
        return parent::beforeValidate(); // TODO: Change the autogenerated stub
    }
    
    public function fixMoneyValues()
    {
        foreach ($this->attributes as $key => $value) {
            if (is_string($value)) {
                if ($value[0] == '$') {
                    $this->setAttribute($key, '' . substr($value, 1));
                }
            }
        }
    }
    
    /**
     * @inheritdoc
     * @return BookingQuery the active query used by this AR class.
     */
    public static function find()
    {
        return (new BookingQuery(get_called_class()))->byCurrentUser();
    }
    
    public function getStayPeriodByDay($day)
    {
        
        $period = CalendarPeriod::find()
            ->where(['item_type' => PeriodTypeEnum::BOOKING_ROOM])
            ->andWhere(['item_root_id' => $this->id])
            ->andWhere(['<=', 'from', $day])
            ->andWhere(['>=', 'to', $day])
            ->one();
        if ($period) {
            return $period->to - $period->from;
        } else {
            throw new Exception('Can not find booking period');
        }
    }
    
    // toref ПЕРЕПИСАТЬ ЭТО ДЕРЬМО. (закинуть в beforeSave() )
    
    public function getPeriods()
    {
        return $this->periodsArray;
    }
    
    public function setGuests($value)
    {
        if (is_array($value)) {
            $this->guestsArray = $value;
            $this->guests = json_encode($value);
        } else {
            $this->guests = $value;
        }
        
    }
    
    public function getGuests()
    {
        if ($this->guestsArray) {
            return $this->guestsArray;
        } elseif ($this->guests) {
            return json_decode($this->guests, TRUE);
        } else {
            return [];
        }
    }
    
    public function setRooms($inputRooms)
    {
        $rooms = [];
        $periods = [];
        foreach ($inputRooms as $room) {
            $season_id = NULL;
            if($_SESSION['daySeasonId']){
                $seasonId = $_SESSION['daySeasonId'];
            } else {
                $seasonId = $season_id;
            }

            if ($room instanceof BookingRoom) {
                $rooms[] = $room;
                $periods[] = $room->period;
            } else {
                $periodRecord = new CalendarPeriod();
                $periodRecord->from = $room['from'] ? TimestampHelper::dateStringToDays($room['from']) : '';
                $periodRecord->to = $room['to'] ? TimestampHelper::dateStringToDays($room['to']) : '';
                $periodRecord->item_type = PeriodTypeEnum::BOOKING_ROOM;
                $periodRecord->item_id = $room['room_id'];
                //$periodRecord->season_id = $room['season_id'];
                $periodRecord->season_id = $seasonId;

                
                $bookingRoomRecord = new BookingRoom();
                $bookingRoomRecord->booking_id = $this->id;
                $bookingRoomRecord->guests_count = $room['guests_count'];
                $bookingRoomRecord->room_id = $room['room_id'];
                $bookingRoomRecord->periodTmp = $periodRecord;
                $rooms[] = $bookingRoomRecord;
                $periods[] = $periodRecord;
            }
            
        }
        $this->roomsArray = $rooms;
        $this->periodsArray = $periods;
    }
    
    public function getRooms()
    {
        if ($this->roomsArray) {
            return $this->roomsArray;
        } else {
            /** @var BookingRoom[] $currentRooms */
            $currentRooms = BookingRoom::find()
                ->where(['booking_id' => $this->id,])
                ->all();
            
            return $currentRooms;
        }
    }

//    public function countSavedTotalPrice()
//    {
//        return (floatval($this->room_extras) + floatval($this->room_price) + floatval($this->adjustment)) * ($this->tax ? ($this->tax / 100) : 1);
//    }
    
    public function getStatusTitle()
    {
        return BookingStatusEnum::getValue($this->status);
    }
    
    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBookingRooms()
    {
        return $this->hasMany(BookingRoom::className(), ['booking_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBookingExtra()
    {
        return $this->hasMany(BookingExtra::className(), ['booking_id' => 'id']);
    }

    public function calendarPeriodSeason($booking_id)
    {
        $bookingRoomsModel = (new Query())->select(['*'])->from('vr_booking_rooms')->where(['booking_id' => $booking_id])->one();
        if($bookingRoomsModel){
            $calendarPeriodModel = (new Query())->select(['*'])->from('vr_calendar_period')->where(['id' => $bookingRoomsModel['period_id']])->one();
            if($calendarPeriodModel){
                if($calendarPeriodModel['season_id']){
                    return (int)$calendarPeriodModel['season_id'];
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }

        /*if($CalendarPeriodModel){
            if($CalendarPeriodModel['season_id']){
                return $CalendarPeriodModel['season_id'];
            } else {
                return false;
            }
        } else {
            return false;
        }*/
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBookingPayments()
    {
        return $this->hasMany(BookingPayment::className(), ['booking_id' => 'id']);
    }


    public function getRoomsModel()
    {
        /** @var BookingRoom $BookingRoom */
        /** @return Room $Rooms */
        $BookingRoom = BookingRoom::find()
            ->where(['booking_id' => $this->id,])
            ->one();

        if($BookingRoom){
            $roomId = $BookingRoom->room_id;
            $Rooms = Room::find()
                ->where(['id' => $roomId])
                ->one();

            if($Rooms){
                return $Rooms;
            } else {
                return "Room not found";
            }

        } else {
            return "Booking not found";
        }
    }

    public function getPeriodFromAndTo($id){

        $calendarPeriod = CalendarPeriod::find()->where(['id' => $id])->one();

        if($calendarPeriod){
            return $calendarPeriod->to - $calendarPeriod->from;
        } else {
            throw new Exception('Can not find calendar period from dashbord');
        }
    }



//    public function validateBookingRooms()
//    {
//        $errors = [];
//        $rooms = $this->rooms;
//        foreach ($rooms as $room) {
//            $validateErrors = $room->validateRoom();
//            if ($validateErrors) {
//                $errors[] = $validateErrors;
//            }
//        }
//        if ($errors) {
//            return $errors;
//        }
//        usort($rooms, function (BookingRoom $record1, BookingRoom $record2) {
//            return $record1->check_in > $record2->check_in;
//        });
//        for ($i = 1; $i < count($rooms); $i++) {
//            $currentRoom = $rooms[$i];
//            $prevRoom = $rooms[$i - 1];
//            if ($prevRoom->check_out >= $currentRoom->check_in) {
//                $errors[] = "Booking periods conflict in period '" . $currentRoom->getDateStringCheckIn() . " - " . $prevRoom->getDateStringCheckOut() . "'";
//            }
//        }
//        return $errors;
//    }
    
    
}
