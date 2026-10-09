import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

// Payment Input Model
class SalePaymentInput {
  String paymentType; // 'CASH', 'CHEQUE', 'ONLINE', 'CARD', 'CREDIT'
  double amount;
  String? referenceNumber;
  String? notes;

  SalePaymentInput({
    required this.paymentType,
    required this.amount,
    this.referenceNumber,
    this.notes,
  });

  Map<String, dynamic> toJson() {
    return {
      'payment_type': paymentType,
      'amount': amount,
      if (referenceNumber != null && referenceNumber!.isNotEmpty)
        'reference_number': referenceNumber,
      if (notes != null && notes!.isNotEmpty) 'notes': notes,
    };
  }
}

class SaleCheckoutAndPaymentScreen extends StatefulWidget {
  final int? shopId;
  final String? shopName;
  final int? dsrTripId;
  final List<Map<String, dynamic>> cartItems; // [{ "item_id": 1, "quantity": 2, "unit_price": 500.0, "name": "Item A" }]
  final String apiBaseUrl; // e.g. "http://10.0.2.2:8000" or "http://192.168.1.100:8000"
  final String authToken;

  const SaleCheckoutAndPaymentScreen({
    Key? key,
    required this.shopId,
    this.shopName,
    this.dsrTripId,
    required this.cartItems,
    required this.apiBaseUrl,
    required this.authToken,
  }) : super(key: key);

  @override
  State<SaleCheckoutAndPaymentScreen> createState() =>
      _SaleCheckoutAndPaymentScreenState();
}

class _SaleCheckoutAndPaymentScreenState
    extends State<SaleCheckoutAndPaymentScreen> {
  String selectedPaymentMode = 'CASH'; // 'CASH', 'CREDIT', 'CHEQUE', 'ONLINE', 'SPLIT'
  List<SalePaymentInput> splitPayments = [];
  
  double discount = 0.0;
  double tax = 0.0;
  final TextEditingController _notesController = TextEditingController();
  final TextEditingController _discountController = TextEditingController();
  final TextEditingController _taxController = TextEditingController();

  bool isSubmitting = false;

  final List<String> paymentOptions = ['CASH', 'CREDIT', 'CHEQUE', 'ONLINE', 'SPLIT'];
  final List<String> individualTypes = ['CASH', 'CHEQUE', 'ONLINE', 'CARD', 'CREDIT'];

  @override
  void initState() {
    super.initState();
    _initializeSinglePayment('CASH');
  }

  double get subtotal {
    return widget.cartItems.fold(0.0, (sum, item) {
      double price = (item['unit_price'] as num).toDouble();
      int qty = item['quantity'] as int;
      double itemDiscount = ((item['discount'] ?? 0.0) as num).toDouble();
      return sum + ((qty * price) - itemDiscount);
    });
  }

  double get totalAmount {
    double total = subtotal - discount + tax;
    return total > 0 ? total : 0.0;
  }

  double get totalAllocatedPayments {
    return splitPayments.fold(0.0, (sum, p) => sum + p.amount);
  }

  double get remainingUnallocated {
    return totalAmount - totalAllocatedPayments;
  }

  void _initializeSinglePayment(String mode) {
    setState(() {
      selectedPaymentMode = mode;
      splitPayments = [
        SalePaymentInput(paymentType: mode, amount: totalAmount)
      ];
    });
  }

  void _addSplitPaymentRow() {
    setState(() {
      double defaultAmount = remainingUnallocated > 0 ? remainingUnallocated : 0.0;
      splitPayments.add(
        SalePaymentInput(paymentType: 'CASH', amount: defaultAmount),
      );
    });
  }

  void _removeSplitPaymentRow(int index) {
    if (splitPayments.length <= 1) return;
    setState(() {
      splitPayments.removeAt(index);
    });
  }

  Future<void> _submitCheckout() async {
    if (selectedPaymentMode == 'SPLIT') {
      if (remainingUnallocated.abs() > 0.01) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              remainingUnallocated > 0
                  ? 'Please allocate the remaining Rs. ${remainingUnallocated.toStringAsFixed(2)}'
                  : 'Allocated payments exceed order total by Rs. ${(-remainingUnallocated).toStringAsFixed(2)}',
            ),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }
    }

    setState(() {
      isSubmitting = true;
    });

    try {
      final Uri url = Uri.parse('${widget.apiBaseUrl}/api/v1/mobile/sales');

      final Map<String, dynamic> payload = {
        if (widget.shopId != null) 'shop_id': widget.shopId,
        if (widget.dsrTripId != null) 'dsr_trip_id': widget.dsrTripId,
        'payment_type': selectedPaymentMode,
        'discount': discount,
        'tax': tax,
        'notes': _notesController.text.trim(),
        'items': widget.cartItems.map((item) => {
          'item_id': item['item_id'],
          'quantity': item['quantity'],
          'unit_price': item['unit_price'],
          if (item['discount'] != null) 'discount': item['discount'],
          if (item['batch_number'] != null) 'batch_number': item['batch_number'],
        }).toList(),
        if (selectedPaymentMode == 'SPLIT' || splitPayments.isNotEmpty)
          'payments': splitPayments.map((p) => p.toJson()).toList(),
      };

      final response = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': 'Bearer ${widget.authToken}',
        },
        body: jsonEncode(payload),
      );

      final responseData = jsonDecode(response.body);

      if (response.statusCode == 201 || response.statusCode == 200) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Sale completed successfully!'),
            backgroundColor: Colors.green,
          ),
        );
        Navigator.pop(context, responseData['data']);
      } else {
        throw Exception(responseData['message'] ?? 'Checkout failed');
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Error: ${e.toString()}'),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) {
        setState(() {
          isSubmitting = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sale Checkout & Payment'),
        backgroundColor: Colors.indigo,
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Shop Summary Card
            if (widget.shopName != null)
              Card(
                elevation: 2,
                margin: const EdgeInsets.only(bottom: 16),
                child: ListTile(
                  leading: const Icon(Icons.storefront, color: Colors.indigo),
                  title: Text(widget.shopName!, style: const TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: Text('Shop ID: ${widget.shopId}'),
                ),
              ),

            // Cart Items Breakdown
            const Text('Order Items Summary', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Card(
              elevation: 1,
              child: ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: widget.cartItems.length,
                separatorBuilder: (context, index) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final item = widget.cartItems[index];
                  double lineSubtotal = (item['quantity'] * item['unit_price']) - ((item['discount'] ?? 0.0) as num).toDouble();
                  return ListTile(
                    title: Text(item['name'] ?? 'Item #${item['item_id']}'),
                    subtitle: Text('${item['quantity']} x Rs. ${item['unit_price']}'),
                    trailing: Text('Rs. ${lineSubtotal.toStringAsFixed(2)}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  );
                },
              ),
            ),
            const SizedBox(height: 16),

            // Discounts & Tax Adjustments
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _discountController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Overall Discount (Rs.)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    onChanged: (val) {
                      setState(() {
                        discount = double.tryParse(val) ?? 0.0;
                        if (selectedPaymentMode != 'SPLIT' && splitPayments.isNotEmpty) {
                          splitPayments.first.amount = totalAmount;
                        }
                      });
                    },
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _taxController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Tax (Rs.)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    onChanged: (val) {
                      setState(() {
                        tax = double.tryParse(val) ?? 0.0;
                        if (selectedPaymentMode != 'SPLIT' && splitPayments.isNotEmpty) {
                          splitPayments.first.amount = totalAmount;
                        }
                      });
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Order Total Header
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.indigo.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.indigo.shade200),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Total Payable:', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  Text(
                    'Rs. ${totalAmount.toStringAsFixed(2)}',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.indigo),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Payment Mode Options Header
            const Text('Payment Option', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),

            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: paymentOptions.map((mode) {
                final isSelected = selectedPaymentMode == mode;
                return ChoiceChip(
                  label: Text(mode, style: TextStyle(color: isSelected ? Colors.white : Colors.black87)),
                  selected: isSelected,
                  selectedColor: Colors.indigo,
                  onSelected: (selected) {
                    if (selected) {
                      if (mode == 'SPLIT') {
                        setState(() {
                          selectedPaymentMode = 'SPLIT';
                          splitPayments = [
                            SalePaymentInput(paymentType: 'CASH', amount: totalAmount * 0.5),
                            SalePaymentInput(paymentType: 'CHEQUE', amount: totalAmount * 0.5),
                          ];
                        });
                      } else {
                        _initializeSinglePayment(mode);
                      }
                    }
                  },
                );
              }).toList(),
            ),

            const SizedBox(height: 16),

            // Split Payments Dynamic Section
            if (selectedPaymentMode == 'SPLIT') ...[
              Card(
                color: Colors.grey.shade50,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8), side: BorderSide(color: Colors.grey.shade300)),
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Split Payment Details', style: TextStyle(fontWeight: FontWeight.bold)),
                          Text(
                            remainingUnallocated == 0
                                ? 'Balanced'
                                : 'Unallocated: Rs. ${remainingUnallocated.toStringAsFixed(2)}',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              color: remainingUnallocated == 0 ? Colors.green : Colors.red,
                            ),
                          ),
                        ],
                      ),
                      const Divider(),

                      ...splitPayments.asMap().entries.map((entry) {
                        int index = entry.key;
                        SalePaymentInput payment = entry.value;

                        return Padding(
                          padding: const EdgeInsets.only(bottom: 10.0),
                          child: Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: Colors.grey.shade300),
                            ),
                            child: Column(
                              children: [
                                Row(
                                  children: [
                                    // Payment Type Dropdown
                                    DropdownButton<String>(
                                      value: payment.paymentType,
                                      underline: const SizedBox(),
                                      items: individualTypes.map((type) {
                                        return DropdownMenuItem(
                                          value: type,
                                          child: Text(type, style: const TextStyle(fontWeight: FontWeight.w600)),
                                        );
                                      }).toList(),
                                      onChanged: (newType) {
                                        if (newType != null) {
                                          setState(() {
                                            payment.paymentType = newType;
                                          });
                                        }
                                      },
                                    ),
                                    const SizedBox(width: 12),

                                    // Amount Field
                                    Expanded(
                                      child: TextField(
                                        keyboardType: TextInputType.number,
                                        decoration: const InputDecoration(
                                          labelText: 'Amount (Rs.)',
                                          isDense: true,
                                          border: OutlineInputBorder(),
                                        ),
                                        controller: TextEditingController(text: payment.amount > 0 ? payment.amount.toString() : '')
                                          ..selection = TextSelection.fromPosition(
                                            TextPosition(offset: payment.amount.toString().length),
                                          ),
                                        onChanged: (val) {
                                          setState(() {
                                            payment.amount = double.tryParse(val) ?? 0.0;
                                          });
                                        },
                                      ),
                                    ),

                                    if (splitPayments.length > 1)
                                      IconButton(
                                        icon: const Icon(Icons.delete_outline, color: Colors.red),
                                        onPressed: () => _removeSplitPaymentRow(index),
                                      ),
                                  ],
                                ),

                                // Reference Field for CHEQUE or ONLINE
                                if (payment.paymentType == 'CHEQUE' || payment.paymentType == 'ONLINE') ...[
                                  const SizedBox(height: 8),
                                  TextField(
                                    decoration: InputDecoration(
                                      labelText: payment.paymentType == 'CHEQUE' ? 'Cheque Number' : 'Transaction Ref No.',
                                      isDense: true,
                                      border: const OutlineInputBorder(),
                                    ),
                                    onChanged: (val) {
                                      payment.referenceNumber = val;
                                    },
                                  ),
                                ],
                              ],
                            ),
                          ),
                        );
                      }).toList(),

                      Align(
                        alignment: Alignment.centerRight,
                        child: TextButton.icon(
                          onPressed: _addSplitPaymentRow,
                          icon: const Icon(Icons.add_circle_outline),
                          label: const Text('Add Payment Method'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
            ],

            // Notes
            TextField(
              controller: _notesController,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'Sale Notes (Optional)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 24),

            // Submit Button
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.indigo,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: isSubmitting ? null : _submitCheckout,
                child: isSubmitting
                    ? const CircularProgressIndicator(color: Colors.white)
                    : Text(
                        'Complete Sale (Rs. ${totalAmount.toStringAsFixed(2)})',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
