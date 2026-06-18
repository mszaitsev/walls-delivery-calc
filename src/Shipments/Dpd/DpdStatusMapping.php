<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class DpdStatusMapping {
	public const MAPPING_KEY = 'dpd_status_mapping';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	/**
	 * @return array<string,array{event_code:string,event_name:string,status_code:string,parameters:array<int,array{name:string,description:string}>,comment:string}>
	 */
	public static function statuses(): array {
		return array(
			'1001' => self::status( '1001', 'Получена заявка', 'OfferCreate', array( 'ClientNumber' => 'Номер клиента', 'ReqOfferId' => 'ИД заявки', 'ClientOrderNumber' => 'Номер заказа в системе клиента', 'OrderPickupDate' => 'Дата приема груза в заказе', 'PickupAddress' => 'Адрес отправителя', 'PickupCity' => 'Город отправления', 'DeliveryAddress' => 'Адрес получателя', 'DeliveryCity' => 'Город получения', 'DeliveryVariant' => 'Вариант приема-доставки', 'ParcelCount' => 'Кол-во посылок в заказе', 'Weight' => 'Вес заказа', 'Volume' => 'Объем заказа', 'AmountNPP' => 'Сумма НПП', 'DeclaredValue' => 'Сумма ОЦ', 'CurrencyDeclaredValue' => 'Валюта ОЦ', 'PickupTermilalCode' => 'Код терминала приема', 'RegularNumber' => 'Номер регулярного заказа', 'PickupAddressCode' => 'Адресный код приема', 'DeliveryAddressCode' => 'Адресный код доставки', 'PickupInterval' => 'Интервал приема', 'SMS' => 'Опция SMS', 'EML' => 'Опция EML', 'PhoneConsignee' => 'Телефон получателя', 'EmailConsignee' => 'Email получателя', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.', 'ProductName' => 'Услуга', 'ConsignorPhone' => 'Телефон отправителя', 'Consignor' => 'Отправитель', 'Consignee' => 'Грузополучатель', 'ShipmentContent' => 'Содержимое' ) ),
			'1101' => self::status( '1101', 'В заявке присутствует ошибка', 'OfferUpdating', array( 'ErrorMessage' => 'Сообщение об ошибке', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'1201' => self::status( '1201', 'Запрошены паспортные данные получателя', 'OfferWaiting' ),
			'1301' => self::status( '1301', 'Отмена заявки', 'OfferCancelled', array( 'ErrorMessage' => 'Сообщение об ошибке', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'1401' => self::status( '1401', 'Заказ создан', 'OrderCreate', array( 'AmountNPP' => 'Сумма НПП', 'Consignee' => 'Получатель', 'Consignor' => 'Отправитель', 'ControlDeliveryMoment' => 'Контрольная дата доставки', 'CurrencyDeclaredValue' => 'Валюта оценочной стоимости', 'CurrencyNPP' => 'Валюта НПП', 'DeclaredValue' => 'Оценочная стоимость', 'DeliveryAddress' => 'Адрес получателя', 'DeliveryCity' => 'Город получателя', 'DeliveryInterval' => 'Интервал доставки', 'DeliveryVariant' => 'Вариант доставки', 'ExtraServices' => 'Опции', 'FreeStoreDate' => 'Дата окончания бесплатного хранения', 'OrderPickupDate' => 'Дата приема груза в заказе', 'ParcelCount' => 'Кол-во посылок в заказе', 'PaymentType' => 'Тип платежа услуг доставки', 'PhoneConsignee' => 'Телефон получателя', 'PhoneConsignor' => 'Телефон отправителя', 'PickupAddress' => 'Адрес отправителя', 'PickupCity' => 'Город отправления', 'PickupTermilalCode' => 'Код терминала отправления', 'PlanDeliveryMoment' => 'Плановая дата доставки', 'ProductName' => 'Услуга', 'ShipmentContent' => 'Описание отправки', 'Weight' => 'Вес заказа' ) ),
			'1501' => self::status( '1501', 'Заказ ожидает дату приема', 'OrderWaiting', array( 'Doc_Num' => 'Номер документа', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'1601' => self::status( '1601', 'Заказ принят у отправителя', 'OrderPickup', array( 'Doc_Num' => 'Номер документа' ) ),
			'1603' => self::status( '1603', 'Заказ ВДО принят у отправителя' ),
			'1701' => self::status( '1701', 'Заказ прибыл в страну доставки', 'OrderArrivedInRF', array( 'Doc_Num' => 'Номер документа', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'1801' => self::status( '1801', 'Закончено таможенное оформление', 'OrderOnTerminal', array( 'PointCyty' => 'Город присвоения события', 'NewOrderNumber' => 'Новый номер заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'1802' => self::status( '1802', 'Прибыл на первый сортировочный комплекс DPD' ),
			'1810' => self::status( '1810', 'Заказ ВДО в ПТ на терминале приёма' ),
			'1811' => self::status( '1811', 'Уточнен состав вложений заказа', 'OrderOnTerminal', array( 'NewOrderNumber' => 'Новый номер заказа', 'OrderType' => 'Тип заказа (только для возврата)', 'PointCity' => 'Город присвоения события', 'UnitLoad1' => 'Перечень актуальных вложений; далее UnitLoad2 и т.д.' ) ),
			'2101' => self::status( '2101', 'Заказ следует по маршруту до терминала доставки', 'OrderOnRoad', array( 'Weght' => 'Вес заказа', 'Volume' => 'Объем заказа', 'ParcelCount' => 'Кол-во посылок в заказе', 'PayWeght' => 'Платный вес отправления', 'PointCity' => 'Город присвоения события', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2102' => self::status( '2102', 'Заказ следует по маршруту до терминала возврата' ),
			'2103' => self::status( '2103', 'Магистральная перевозка до терминала доставки заказа ВДО' ),
			'2201' => self::status( '2201', 'Заказ готов к выдаче на пункте', 'OrderReady', array( 'CodeDepartment' => 'Код подразделения', 'DPDParcelNum1' => 'Номер посылки DPD', 'DeliveryVariant' => 'Вариант приема-доставки', 'ExtraServices' => 'Услуги', 'FreeStoreDate' => 'Дата окончания БХР', 'PhoneConsignee' => 'Номер получателя', 'PointCity' => 'Город присвоения события', 'Weight' => 'Вес', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)' ) ),
			'2202' => self::status( '2202', 'Заказ готов к передаче курьеру для доставки', '', array( 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.', 'PhoneConsignee' => 'Телефон получателя', 'SMS' => 'Опция SMS' ) ),
			'2203' => self::status( '2203', 'Заказ на возврат готов к выдаче' ),
			'2204' => self::status( '2204', 'Заказ на возврат готов к передаче курьеру для доставки' ),
			'2205' => self::status( '2205', 'Таможенное оформление в стране отправления' ),
			'2209' => self::status( '2209', 'Заказ ВДО готов к выдаче на терминале' ),
			'2210' => self::status( '2210', 'Заказ ВДО готов к доставке до двери' ),
			'2301' => self::status( '2301', 'Заказ доставляется получателю', 'OrderDelivering', array( 'Weght' => 'Вес заказа', 'Volume' => 'Объем заказа', 'ParcelCount' => 'Кол-во посылок в заказе', 'PayWeght' => 'Платный вес отправления', 'PointCity' => 'Город присвоения события', 'DeliveryInterval' => 'Вариант приема-доставки', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.', 'RegisterDeliveryNumber' => 'Номер реестра накладных при доставке' ) ),
			'2302' => self::status( '2302', 'Таможенное оформление закончено в стране отправления' ),
			'2303' => self::status( '2303', 'Отправка на транзитном терминале за рубежом' ),
			'2304' => self::status( '2304', 'Доставляется получателю за рубежом' ),
			'2305' => self::status( '2305', 'Возвращается отправителю из-за рубежа' ),
			'2306' => self::status( '2306', 'Заказ готов к доставке за рубежом' ),
			'2307' => self::status( '2307', 'Проблема при доставке за рубежом' ),
			'2309' => self::status( '2309', 'Заказ доставляется отправителю', '', array( 'DeliveryInterval' => 'Вариант приема-доставки', 'ParcelCount' => 'Кол-во посылок в заказе', 'PayWeight' => 'Платный вес', 'Volume' => 'Объем', 'Weight' => 'Вес', 'PointCity' => 'Город присвоения события', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2310' => self::status( '2310', 'Передано спецперевозчику', 'OrderDelivering', array( 'DeliveryInterval' => 'Вариант приема-доставки', 'ParcelCount' => 'Кол-во посылок в заказе', 'PayWeight' => 'Платный вес', 'Volume' => 'Объем', 'Weight' => 'Вес', 'PointCity' => 'Город присвоения события', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2311' => self::status( '2311', 'Введен штрихкод накладной спецперевозчика', 'OrderDelivering', array( 'CarrierNum' => 'Номер накладной спецперевозчика', 'ParcelNum' => 'Номер посылки', 'OrderNumber' => 'Номер заказа', 'ClientOrderNumber' => 'Код внутреннего заказа клиента', 'ClientCodeInternal' => 'Дополнительный код клиентского заказа' ) ),
			'2314' => self::status( '2314', 'Заказ ВДО доставляется отправителю', 'OrderDelivering', array( 'DeliveryInterval' => 'Вариант приема-доставки', 'ParcelCount' => 'Кол-во посылок в заказе', 'PayWeight' => 'Платный вес', 'Volume' => 'Объем', 'Weight' => 'Вес', 'PointCity' => 'Город присвоения события', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2401' => self::status( '2401', 'Истек срок бесплатного хранения заказа', 'OrderProblem', self::problem_parameters() ),
			'2402' => self::status( '2402', 'Оплата за товар по заказу не произведена Получателем' ),
			'2404' => self::status( '2404', 'Отказ от заказа в момент доставки', 'OrderDeliveryProblem' ),
			'2405' => self::status( '2405', 'Отказ от заказа по желанию получателя через веб-службу «Управление доставкой»', 'OrderProblem' ),
			'2406' => self::status( '2406', 'Отказ от заказа по желанию получателя через контакт центр' ),
			'2407' => self::status( '2407', 'Получатель отсутствует на адресе доставки', 'OrderDeliveryProblem' ),
			'2408' => self::status( '2408', 'Указан неправильный адрес доставки' ),
			'2409' => self::status( '2409', 'Задержано на таможне', 'OrderProblem' ),
			'2410' => self::status( '2410', 'Другие проблемы при доставке', 'OrderDeliveryProblem' ),
			'2411' => self::status( '2411', 'Отказано в таможенном оформлении по причине неуплаты таможенных пошлин', 'OrderProblem' ),
			'2416' => self::status( '2416', 'Отмена заказа клиентом по пути на терминал доставки', 'OrderProblem' ),
			'3701' => self::status( '3701', 'Заказ поврежден', 'OrderProblem' ),
			'2501' => self::status( '2501', 'Услуга не оказана', 'OrderDied', array( 'ReasonName' => 'Наименование причины изменения', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2601' => self::status( '2601', 'Произведен предварительный расчет стоимости доставки', 'OrderInvoice', array( 'Doc_Num' => 'Номер документа', 'Doc_Amount' => 'Сумма документа', 'Doc_Currency' => 'Валюта документа', 'Doc_Vat' => 'НДС', 'Doc_Date' => 'Дата документа', 'Doc_Name' => 'Наименование документа', 'Payer' => 'Плательщик (только для события 2602)', 'Paymethod' => 'Метод оплаты (только для события 2602)' ) ),
			'2602' => self::status( '2602', 'Выставлен счет' ),
			'2701' => self::status( '2701', 'Наложенный платёж принят у получателя; наложенный платёж перечислен интернет-магазину', 'OrderCODConfirmed', array( 'AmountNPP' => 'Сумма НПП', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'PaymentWay' => 'Оплата наличными/Оплата картой/Онлайн оплата по SBP', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2801' => self::status( '2801', 'Наложенный платёж перечислен интернет-магазину', 'OrderCODSent', array( 'Doc_Num' => 'Номер документа', 'Doc_Amount' => 'Сумма документа', 'Doc_Currency' => 'Валюта документа', 'Doc_Date' => 'Дата документа' ) ),
			'2901' => self::status( '2901', 'Заказ отменен', 'OrderCancelled', array( 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'2904' => self::status( '2904', 'Заказ ВДО отменён' ),
			'3001' => self::status( '3001', 'Произведен расчет стоимости за платное хранение', 'OrderPaidStorage', array( 'Doc_Num' => 'Номер документа', 'Doc_Amount' => 'Сумма документа' ) ),
			'3201' => self::status( '3201', 'Перенос даты доставки по инициативе DPD после звонка получателю (увеличение)', 'OrderChangeDeliveryCondition', self::delivery_change_parameters() ),
			'3202' => self::status( '3202', 'Изменены условия доставки получателем во время доставки' ),
			'3203' => self::status( '3203', 'Изменены условия доставки получателем через веб-службу «Управление доставкой»' ),
			'3204' => self::status( '3204', 'Изменены условия доставки получателем через call-centre' ),
			'3205' => self::status( '3205', 'Изменена дата доставки' ),
			'3206' => self::status( '3206', 'Изменена дата доставки по инициативе DPD на терминале (увеличение)' ),
			'3211' => self::status( '3211', 'Перенос даты доставки по инициативе DPD (уменьшение)' ),
			'3216' => self::status( '3216', 'Изменена дата доставки по инициативе DPD (уменьшение)' ),
			'3301' => self::status( '3301', 'Заказ утилизирован', 'OrderWorkCompleted', array( 'OrderNumber' => 'Номер заказа', 'OrderPickupDate' => 'Дата приёма заказа', 'OrderDeliveryDate' => 'Дата доставки заказа', 'ReasonName' => 'Наименование причины изменения', 'ConsigneeFIO' => 'ФИО получателя', 'ParcelCount' => 'Кол-во посылок в заказе', 'PointCode' => 'Код пункта', 'PointCity' => 'Город присвоения события', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'3302' => self::status( '3302', 'Посылка не востребована' ),
			'3303' => self::status( '3303', 'Заказ утерян' ),
			'3304' => self::status( '3304', 'Заказ доставлен до двери', '', array(), 'В документации отмечен как 3304*; с версии 1.24 возможно получение информации по частичной доставке, с версии 1.26 - причины отказа клиента от товара.' ),
			'3305' => self::status( '3305', 'Заказ выдан на ПВЗ', '', array(), 'В документации отмечен как 3305*; с версии 1.24 возможно получение информации по частичной доставке, с версии 1.26 - причины отказа клиента от товара.' ),
			'3306' => self::status( '3306', 'Заказ на возврат доставлен' ),
			'3308' => self::status( '3308', 'Заказ ВДО доставлен' ),
			'3401' => self::status( '3401', 'Накладная в электронном архиве', 'OrderEAWaybill' ),
			'3501' => self::status( '3501', 'N-я повторная бесплатная доставка', 'OrderRepeatDelivering', array( 'ReDeliveryType' => 'Тип повторной доставки', 'ReDeliveryNumber' => 'Номер повторной доставки', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'3601' => self::status( '3601', 'N-я повторная платная доставка' ),
			'3901' => self::status( '3901', 'Направлено сообщение Email', 'OrderEmailSent', array( 'AddressMail' => 'Адресат', 'SubjectMail' => 'Тема письма', 'ReasonName' => 'Наименование причины уведомления', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'4001' => self::status( '4001', 'Звонок получателю', 'OrderCallToConsignee', array( 'CallResult' => 'Результат звонка', 'ReasonName' => 'Наименование причины', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
			'4101' => self::status( '4101', 'Направлено сообщение SMS', 'OrderSMSSent', array( 'PhoneNumber' => 'Номер телефона', 'ReasonName' => 'Наименование причины', 'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)', 'OrderType' => 'Тип заказа (только для возврата)', 'MomentLocZone' => 'Смещение локального времени относительно Гринвича.' ) ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function default_mapping(): array {
		return array(
			'1001' => DeliveryStatus::CREATED_IN_CARRIER,
			'1101' => DeliveryStatus::REJECTED,
			'1201' => DeliveryStatus::CREATED_IN_CARRIER,
			'1301' => DeliveryStatus::CANCELLED,
			'1401' => DeliveryStatus::CREATED_IN_CARRIER,
			'1501' => DeliveryStatus::CREATED_IN_CARRIER,
			'1601' => DeliveryStatus::IN_TRANSIT,
			'1603' => DeliveryStatus::IN_TRANSIT,
			'1701' => DeliveryStatus::IN_TRANSIT,
			'1801' => DeliveryStatus::IN_TRANSIT,
			'1802' => DeliveryStatus::IN_TRANSIT,
			'1810' => DeliveryStatus::IN_TRANSIT,
			'1811' => DeliveryStatus::IN_TRANSIT,
			'2101' => DeliveryStatus::IN_TRANSIT,
			'2102' => DeliveryStatus::RETURNING_TO_SENDER,
			'2103' => DeliveryStatus::IN_TRANSIT,
			'2201' => DeliveryStatus::READY_FOR_PICKUP,
			'2202' => DeliveryStatus::HANDED_TO_COURIER,
			'2203' => DeliveryStatus::RETURNING_TO_SENDER,
			'2204' => DeliveryStatus::RETURNING_TO_SENDER,
			'2205' => DeliveryStatus::IN_TRANSIT,
			'2209' => DeliveryStatus::READY_FOR_PICKUP,
			'2210' => DeliveryStatus::HANDED_TO_COURIER,
			'2301' => DeliveryStatus::HANDED_TO_COURIER,
			'2302' => DeliveryStatus::IN_TRANSIT,
			'2303' => DeliveryStatus::IN_TRANSIT,
			'2304' => DeliveryStatus::HANDED_TO_COURIER,
			'2305' => DeliveryStatus::RETURNING_TO_SENDER,
			'2306' => DeliveryStatus::IN_TRANSIT,
			'2307' => DeliveryStatus::UNKNOWN,
			'2309' => DeliveryStatus::RETURNING_TO_SENDER,
			'2310' => DeliveryStatus::IN_TRANSIT,
			'2311' => DeliveryStatus::IN_TRANSIT,
			'2314' => DeliveryStatus::RETURNING_TO_SENDER,
			'2401' => DeliveryStatus::REJECTED,
			'2402' => DeliveryStatus::REJECTED,
			'2404' => DeliveryStatus::REJECTED,
			'2405' => DeliveryStatus::REJECTED,
			'2406' => DeliveryStatus::REJECTED,
			'2407' => DeliveryStatus::REJECTED,
			'2408' => DeliveryStatus::REJECTED,
			'2409' => DeliveryStatus::IN_TRANSIT,
			'2410' => DeliveryStatus::REJECTED,
			'2411' => DeliveryStatus::REJECTED,
			'2416' => DeliveryStatus::CANCELLED,
			'3701' => DeliveryStatus::REJECTED,
			'2501' => DeliveryStatus::REJECTED,
			'2601' => DeliveryStatus::UNKNOWN,
			'2602' => DeliveryStatus::UNKNOWN,
			'2701' => DeliveryStatus::DELIVERED,
			'2801' => DeliveryStatus::DELIVERED,
			'2901' => DeliveryStatus::CANCELLED,
			'2904' => DeliveryStatus::CANCELLED,
			'3001' => DeliveryStatus::UNKNOWN,
			'3201' => DeliveryStatus::IN_TRANSIT,
			'3202' => DeliveryStatus::IN_TRANSIT,
			'3203' => DeliveryStatus::IN_TRANSIT,
			'3204' => DeliveryStatus::IN_TRANSIT,
			'3205' => DeliveryStatus::IN_TRANSIT,
			'3206' => DeliveryStatus::IN_TRANSIT,
			'3211' => DeliveryStatus::IN_TRANSIT,
			'3216' => DeliveryStatus::IN_TRANSIT,
			'3301' => DeliveryStatus::RETURNED_TO_SENDER,
			'3302' => DeliveryStatus::RETURNED_TO_SENDER,
			'3303' => DeliveryStatus::REJECTED,
			'3304' => DeliveryStatus::DELIVERED,
			'3305' => DeliveryStatus::DELIVERED,
			'3306' => DeliveryStatus::RETURNED_TO_SENDER,
			'3308' => DeliveryStatus::DELIVERED,
			'3401' => DeliveryStatus::DELIVERED,
			'3501' => DeliveryStatus::HANDED_TO_COURIER,
			'3601' => DeliveryStatus::HANDED_TO_COURIER,
			'3901' => DeliveryStatus::UNKNOWN,
			'4001' => DeliveryStatus::UNKNOWN,
			'4101' => DeliveryStatus::UNKNOWN,
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function mapping(): array {
		$stored = $this->settings->get_array( self::MAPPING_KEY, array() );

		return $this->sanitize_mapping( array_replace( self::default_mapping(), $stored ) );
	}

	public function resolve( string $event_code, ?string $param_name = null ): string {
		unset( $param_name );
		$event_code = $this->normalize_event_code( $event_code );
		if ( '' === $event_code ) {
			return DeliveryStatus::UNKNOWN;
		}

		return (string) ( $this->mapping()[ $event_code ] ?? DeliveryStatus::UNKNOWN );
	}

	/**
	 * @param array<string,mixed> $mapping
	 */
	public function save_mapping( array $mapping ): void {
		$this->settings->set( self::MAPPING_KEY, $this->sanitize_mapping( $mapping ) );
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array<string,string>
	 */
	public function sanitize_mapping( array $mapping ): array {
		$result = array();
		foreach ( array_keys( self::statuses() ) as $event_code ) {
			$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $mapping[ $event_code ] ?? '' ) ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) ( $mapping[ $event_code ] ?? '' ) ) ?? '' );
			$result[ $event_code ] = DeliveryStatus::is_valid( $value ) ? $value : ( self::default_mapping()[ $event_code ] ?? DeliveryStatus::UNKNOWN );
		}

		return $result;
	}

	/**
	 * @param array<string,string> $parameters
	 * @return array{event_code:string,event_name:string,status_code:string,parameters:array<int,array{name:string,description:string}>,comment:string}
	 */
	private static function status( string $event_code, string $event_name, string $status_code = '', array $parameters = array(), string $comment = '' ): array {
		return array(
			'event_code' => $event_code,
			'event_name' => $event_name,
			'status_code' => $status_code,
			'parameters' => array_map(
				static fn ( string $name, string $description ): array => array( 'name' => $name, 'description' => $description ),
				array_keys( $parameters ),
				array_values( $parameters )
			),
			'comment' => $comment,
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function problem_parameters(): array {
		return array(
			'DataChannel' => 'Канал получения информации',
			'PointCity' => 'Город присвоения события',
			'NewOrderNumber' => 'Номер возвратного заказа (только для возврата)',
			'OrderType' => 'Тип заказа (только для возврата)',
			'MomentLocZone' => 'Смещение локального времени относительно Гринвича.',
			'ReasonCode' => 'Код причины события',
			'ReasonName' => 'Наименование причины',
			'ChannelCode' => 'Код канала (только для событий 2405, 2406, 3203 и 3204)',
			'ChannelName' => 'Имя канала (только для событий 2405, 2406, 3203 и 3204)',
			'RejectionReason' => 'Причина отказа (только для событий 2404, 2405, 2406, 3701)',
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function delivery_change_parameters(): array {
		return array(
			'DeliveryAddress' => 'Адрес получателя',
			'DeliveryInterval' => 'Интервал доставки',
			'DeliveryVariant' => 'Вариант приема-доставки',
			'PlanDeliveryMoment' => 'Плановая дата доставки',
			'ControlDeliveryMoment' => 'Контрольная дата доставки',
			'DeliveryPointCode' => 'Код пункта доставки',
			'DataChannel' => 'Канал получения информации',
			'PointCity' => 'Город присвоения события',
			'ParcelCount' => 'Кол-во посылок в заказе',
			'MomentLocZone' => 'Смещение локального времени относительно Гринвича.',
			'NewOrderNumber' => 'Номер возвратного заказа',
			'OrderType' => 'Тип заказа',
			'FreeStoreDate' => 'Дата окончания БХР',
			'ProblemReasonName' => 'Название причины проблемы (только события 3205, 3206)',
			'ProblemReasonCode' => 'Код причины проблемы (только события 3205, 3206)',
			'PhoneConsignee' => 'Телефон получателя (только события 3203, 3204)',
			'SMS' => 'Опция SMS (только события 3203, 3204)',
			'DeliveryAddressCode' => 'Адресный код доставки (только для событий 3202, 3203, 3204)',
			'OldDeliveryAddress' => 'Старый адрес получателя (только для событий 3202, 3203, 3204)',
			'OldDeliveryInterval' => 'Старый интервал доставки (только для событий 3202, 3203, 3204)',
			'OldPlanDeliveryMoment' => 'Старая плановая дата доставки (только для событий 3202, 3203, 3204)',
		);
	}

	private function normalize_event_code( string $event_code ): string {
		return preg_replace( '/\D+/', '', trim( $event_code ) ) ?? '';
	}
}
