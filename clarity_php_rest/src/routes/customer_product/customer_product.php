<?php
/*
changelog
----------
Amv1 - Fix Product list not displaying products which does not have GIN but contain GRN record 
*/

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response;


$app->get('/mycargo/products/list', function (Request $request, Response $response, array $args) {

    $queryParams = $request->getQueryParams();
    
    if (!isset($queryParams['customer'])) {
        $customerID = $request->getParsedBody()['User']['group'];
    } else {
        $customerID = $queryParams['customer'];
    }

    // var_dump($customerID);
    // var_dump($request->getParsedBody());

    $connection = db_Connect();
    $productStmt = "SELECT ID, Part_No, Product_Name, Product_Description, Unique_Product_Code, SKU, Serialised FROM products WHERE Owner_ID = ? ORDER BY Product_Name";
    $productSQL = $connection->prepare($productStmt);
    $productSQL->bind_param('s', $customerID);
    $productSQL->execute();
    $productResults = $productSQL->get_result();

    // $product = $productResults->fetch_assoc();

    //Amv1
    $product = array();

    //Amv1
    $stockStmt ="SELECT Total_GRN.Product_ID, Total_GRN.Part_No, Total_GRN.Product_Name, Total_GRN.Product_Description, Total_GRN.Unique_Product_Code,
                Total_GRN.SKU, Total_GRN.Serialised,(Total_GRN.Total_Checked_In - IFNULL(Total_GIN.Total_Checked_Out, '0')) as Balance,
                CAST(Total_GRN.Total_Expected_In AS UNSIGNED) as Expected_Inbound, CAST(Total_GIN.Total_Expected_Out AS UNSIGNED) as Expected_Outbound,
                CAST(Total_GRN.Total_Checked_In AS UNSIGNED) as Actual_Inbound, CAST(Total_GIN.Total_Checked_Out AS UNSIGNED) as Actual_Outbound
                FROM
                (
                SELECT D.ID as Product_ID, D.Part_No as Part_No, D.Product_Name as Product_Name, D.Product_Description as Product_Description,
                D.Unique_Product_Code as Unique_Product_Code, D.SKU as SKU, D.Serialised as Serialised,
                A.Item_Record_ID, SUM(A.Actual_Quantity*A.Denominator) as Total_Checked_In, SUM(A.Quantity) as Total_Expected_In,
                ANY_VALUE(B.Expected_Date)
                FROM goods_received_item_records A
                LEFT JOIN goods_receipt_notes B ON A.Goods_Receipt_Note = B.ID
                LEFT JOIN item_records C ON C.ID = A.Item_Record_ID
                LEFT JOIN products D ON C.Product_ID = D.ID
                WHERE D.Owner_ID = ?
                GROUP BY D.ID
                ) Total_GRN
                LEFT JOIN
                (
                SELECT D.ID as Product_ID, A.Item_Record_ID, SUM(A.Actual_Quantity*A.Denominator) as Total_Checked_Out, SUM(A.Quantity) as Total_Expected_Out,
                ANY_VALUE(B.Expected_Date)
                FROM goods_issued_item_records as A
                LEFT JOIN goods_issued_notes B ON A.Goods_Issued_Note_ID = B.ID
                LEFT JOIN item_records C ON C.ID = A.Item_record_ID
                LEFT JOIN products D ON C.Product_ID = D.ID
                WHERE D.Owner_ID = ?
                GROUP BY D.ID
                ) Total_GIN
                ON Total_GRN.Product_ID = Total_GIN.Product_ID
                GROUP BY Total_GRN.Product_ID;";
    
    
    $stockSQL = $connection->prepare($stockStmt);
    $stockSQL->bind_param('ss', $customerID,  $customerID);
    $stockSQL->execute();
    $stockResult = $stockSQL->get_result();
    // $stock = $stockResult->fetch_assoc();

    //amend End

    $products = array();

    //This make the SQL query slow and use more IO in database as the query will run for each of the record generated.
    //As DB size goes up, it will more slower
    while ($stock = $stockResult->fetch_assoc()) {

        //amend - 02/08/2024
        $product['ID'] = $stock['Product_ID'];
        $product['Part_No'] = $stock['Part_No'];
        $product['Product_Name'] = $stock['Product_Name'];
        $product['Product_Description'] = $stock['Product_Description'];
        $product['Unique_Product_Code'] = $stock['Unique_Product_Code'];
        $product['SKU'] = $stock['SKU'];
        $product['Serialised'] = $stock['Serialised'];
        //amend End

        if (isset($stock['Balance'])) {
            $product['Balance'] = $stock['Balance'];
        } else {
            $product['Balance'] = "-";
        }
        if (isset($stock['Expected_Inbound'])) {
            $product['Expected_Inbound'] =  $stock['Expected_Inbound'];
        } else {
            $product['Expected_Inbound'] = "-";
        }
        if (isset($stock['Expected_Outbound'])) {
            $product['Expected_Outbound'] =  $stock['Expected_Outbound'];
        } else {
            $product['Expected_Outbound'] = "-";
        }
        if (isset($stock['Actual_Inbound'])) {
            $product['Actual_Inbound'] =  $stock['Actual_Inbound'];
        } else {
            $product['Actual_Inbound'] = "-";
        }
        if (isset($stock['Actual_Outbound'])) {
            $product['Actual_Outbound'] =  $stock['Actual_Outbound'];
        } else {
            $product['Actual_Outbound'] = "-";
        }
        $products[] = $product;
    };

    usort($products, function ($a, $b) {
        if ($a['Balance'] == "-") return 1;
        if ($b['Balance'] == "-") return -1;
        return $a['Balance'] - $b['Balance'];
    });

    $response->getBody()->write(json_encode($products));
    return $response->withStatus(200)
        ->withHeader('Content-Type', 'application/json');
});

$app->get('/mycargo/products/profile/{ID}', function (Request $request, Response $response, array $args) {
    $productID = $args['ID'];

    if (!isset($productID)) {
        return $response->withStatus(403);
    }

    $connection = db_connect();

    $detailsStmt = "SELECT * FROM products WHERE ID = ?";
    $detailsSQL = $connection->prepare($detailsStmt);
    $detailsSQL->bind_param('s', $productID);
    $detailsSQL->execute();
    $details = $detailsSQL->get_result()->fetch_assoc();
    $connection->close();

    $response->getBody()->write(json_encode($details));
    return $response->withStatus(200)
        ->withHeader('Content-Type', 'application/json');
});

$app->get('/mycargo/products/profile/{ID}/available', function (Request $request, Response $response, array $args) {
    $connection = db_connect();
    $productID = $args['ID'];
    $availableStmt = "SELECT Total_GRN.Item_Record_ID, Total_GRN.GRN_ID, Total_GIN.GIN_ID,Total_GRN.GRN_No, Total_GIN.GIN_No, Total_GRN.Serial_No, Total_GRN.Item_SKU,Total_GRN.Product_SKU, Total_GRN.Dollar_Value, Total_GRN.Currency,
            Total_GRN.Part_No, (SELECT Name FROM warehouse WHERE warehouse.ID = Total_GRN.Storage_Location) as Storage_Location, Total_GRN.Bin_Location,
            CASE WHEN (Total_GRN.Total_Checked_In - IFNULL(Total_GIN.Total_Checked_Out, '0')) < 0 THEN 0 ELSE (Total_GRN.Total_Checked_In - IFNULL(Total_GIN.Total_Checked_Out, '0')) END as Balance, 
            Total_GRN.Denominator, Total_GRN.Actual_Quantity, Total_GRN.Completed_DateTime, Total_GRN.Expected_Date,
            CASE WHEN (Total_GRN.Total_Checked_In - IFNULL(Total_GIN.Total_Checked_Out, '0')) > 0 THEN 0 ELSE (IFNULL(Total_GIN.Total_Checked_Out, '0') - Total_GRN.Total_Checked_In) END as Shortfall,
            Total_GRN.Expected_Quantity as Expected_Inbound, Total_GRN.Actual_Quantity as Actual_Inbound, Total_GRN.Expiry_Date, Total_GRN.Age, (SELECT CONCAT(Rack,'-',Name) FROM warehouse_locations WHERE ID = Total_GRN.Bin_Location) as Bin_Display_Name
            FROM
                (SELECT B.ID as GRN_ID, C.Serial_No, D.Product_Name, D.ID as Product_ID , A.Item_Record_ID, A.Storage_Location, K.Dollar_Value, K.Currency,
                SUM(CASE WHEN (B.Completed_DateTime IS NOT NULL) THEN A.Actual_Quantity ELSE 0 END) as Total_Checked_In, A.Denominator, A.Quantity as Expected_Quantity, A.Actual_Quantity, 
                SUM(CASE WHEN (B.Completed_DateTime IS NULL) THEN A.Quantity ELSE 0 END) as Total_Expected_In,B.Expected_Date, B.GRN_No, A.Bin_Location,
                B.Completed_DateTime, D.SKU as Product_SKU, D.Part_No, C.SKU as Item_SKU, C.Expiry_Date, DATEDIFF(C.Expiry_Date, C.Created_DateTime) as Age
                FROM goods_received_item_records A 
                LEFT JOIN goods_receipt_notes B ON A.Goods_Receipt_Note = B.ID 
                LEFT JOIN item_records C ON C.ID = A.Item_Record_ID
                LEFT JOIN booking_items K ON A.Booking_Item_ID = K.ID
                LEFT JOIN products D ON C.Product_ID = D.ID
                WHERE C.Product_ID = ?
                GROUP BY Item_Record_ID)
                    as Total_GRN
            LEFT JOIN
                (SELECT B.ID as GIN_ID, D.Product_Name, D.ID as Product_ID, A.Item_Record_ID, SUM(A.Actual_Quantity) as Total_Checked_Out, SUM(A.Quantity) as Total_Expected_Out, B.Expected_Date, B.GIN_No
                FROM goods_issued_item_records as A 
                LEFT JOIN goods_issued_notes B ON A.Goods_Issued_Note_ID = B.ID
                LEFT JOIN item_records C ON C.ID = A.Item_Record_ID
                LEFT JOIN products D ON C.Product_ID = D.ID
                WHERE Product_ID = ?
                GROUP BY Item_Record_ID)
                    as Total_GIN
                        ON Total_GRN.Item_Record_ID = Total_GIN.Item_Record_ID";
    $availableSQL = $connection->prepare($availableStmt);
    $availableSQL->bind_param('ss', $productID, $productID);
    $availableSQL->execute();
    $available = mysqli_fetch_all($availableSQL->get_result(), MYSQLI_ASSOC);

    $output = array();

    foreach($available as $loadedItem) {
        if($loadedItem['Balance'] > 0) {
            $output[] = $loadedItem;
        }
    }

    $queryParams = $request->getQueryParams();
    if (isset($queryParams['sortBy']) && isset($queryParams['dir'])) {
        $sortBy = $queryParams['sortBy'];
        $dir = $queryParams['dir'];

        switch ($sortBy) {
            case 'age':
                if ($dir === 'asc') {
                    usort($output, function ($a, $b) {
                        return $a['Age'] - $b['Age'];
                    });
                } else if ($dir === 'dsc') {
                    usort($output, function ($a, $b) {
                        return $b['Age'] - $a['Age'];
                    });
                }
                break;
            case 'expiry':
                if ($dir === 'asc') {
                    usort($output, function ($a, $b) {
                        return strtotime($a['Expiry_Date']) - strtotime($b['Expiry_Date']);
                    });
                } else if ($dir === 'dsc') {
                    usort($output, function ($a, $b) {
                        return strtotime($b['Expiry_Date']) - strtotime($a['Expiry_Date']);
                    });
                }
                break;
        }
    }

    $response->getBody()->write(json_encode($output));
    return $response->withStatus(200)
        ->withHeader('Content-Type', 'application/json');
});

$app->get('/mycargo/products/profile/{ID}/history', function (Request $request, Response $response, array $args) {
    $connection = db_connect();
    $productID = $args['ID'];
    $historyStmt = "(SELECT 'Inbound' as Type, B.GRN_No as Transaction_Ref, B.ID as Transaction_ID, A.Item_Record_ID, A.Bin_Location, 
		(SELECT Name from warehouse WHERE warehouse.ID = A.Storage_Location) as Storage_Location, SUM(A.Actual_Quantity) as Quantity, Verified_By, Completed_DateTime,
        (SELECT CONCAT(Rack,'-',Name) FROM warehouse_locations WHERE ID = A.Bin_Location) as Bin_Display_Name
		FROM goods_received_item_records A 
		LEFT JOIN goods_receipt_notes B ON A.Goods_Receipt_Note = B.ID 
		LEFT JOIN item_records C ON C.ID = A.Item_Record_ID
		LEFT JOIN products D ON C.Product_ID = D.ID
		LEFT JOIN warehouse E ON E.ID = A.Storage_Location
		WHERE C.Product_ID = ? GROUP BY Item_Record_ID) 
		UNION ALL
		(SELECT 'Outbound' as Type, B.GIN_No as Transaction_Ref, B.ID as Transaction_ID, A.Item_Record_ID, A.Bin_Location, 
		(SELECT Name from warehouse WHERE warehouse.ID = A.Storage_Location) as Storage_Location, SUM(A.Actual_Quantity) as Quantity, Verified_By, Completed_DateTime,
        (SELECT CONCAT(Rack,'-',Name) FROM warehouse_locations WHERE ID = A.Bin_Location) as Bin_Display_Name
		FROM goods_issued_item_records as A 
		LEFT JOIN goods_issued_notes B ON A.Goods_Issued_Note_ID = B.ID
		LEFT JOIN item_records C ON C.ID = A.Item_Record_ID
		LEFT JOIN products D ON C.Product_ID = D.ID
		WHERE Product_ID = ? GROUP BY Item_Record_ID)";
    $historySQL = $connection->prepare($historyStmt);
    $historySQL->bind_param('ss', $productID, $productID);
    $historySQL->execute();
    $history = mysqli_fetch_all($historySQL->get_result(), MYSQLI_ASSOC);

    usort($history, function ($a, $b) {
        return strtotime($b['Completed_DateTime']) - strtotime($a['Completed_DateTime']);
    });

    $response->getBody()->write(json_encode($history));
    return $response->withStatus(200)
        ->withHeader('Content-Type', 'application/json');
});

$app->get('/mycargo/products/profile/{ID}/bookings', function (Request $request, Response $response, array $args) {
    $productID = $args['ID'];
    $connection = db_connect();

    // Get all the bookings starting WITH **products** table and narrow down to **bookings** table.
    $bookingsStmt = "SELECT A.SKU, B.Serial_No, D.Booking_Type, C.Quantity, D.Ref_No as Booking_Ref, D.Customer_Ref_No1 as Customer_Ref, D.DateTime_Created as Booking_Date, D.Status, E.Name as Location_From, F.Name as Location_To
        FROM products A INNER JOIN
            item_records B ON B.Product_ID = A.ID INNER JOIN
            booking_items C ON C.Item_Record_ID = B.ID INNER JOIN
            bookings D ON D.ID = C.Booking_ID INNER JOIN
            locations E ON E.ID = D.Pick_Up_Location_ID INNER JOIN
            locations F ON F.ID = D.Delivery_Location_ID WHERE A.ID = ?";

    $bookingsSQL = $connection->prepare($bookingsStmt);
    $bookingsSQL->bind_param('s', $productID);
    $bookingsSuccess = $bookingsSQL->execute();
    if (!$bookingsSuccess) {
        return eventError($response, "Product profile bookings failed. (1)");
    }

    $bookings = mysqli_fetch_all($bookingsSQL->get_result(), MYSQLI_ASSOC);
    return eventSuccessWithResponse($response, $bookings);
});
